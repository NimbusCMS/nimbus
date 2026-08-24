<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

/**
 * ADMIN-10: admin post-redirect notices are resolved from fixed CODES, never
 * rendered from free `?err=`/`?msg=` query text — so a crafted link can no
 * longer paint attacker-chosen text into a trusted admin banner (a phishing /
 * social-engineering aid). This guards the closed loop across every notice
 * surface, plus two static drift guards so a future controller/template can't
 * silently re-open the channel.
 */
final class AdminNoticeTest extends HttpTestCase
{
    private const MARKER = 'HOSTILE-7f3a-marker';

    public function test_no_admin_notice_surface_reflects_query_text(): void
    {
        $this->actingAs('admin');
        $this->makeCollection('posts', [], ['kind' => 'collection', 'permissions' => ['manage' => ['admin']]]);
        $this->makeCollection('homepage', [], ['kind' => 'single', 'permissions' => ['manage' => ['admin']]]);

        $surfaces = [
            '/admin/media',
            '/admin/users',
            '/admin/roles',
            '/admin/tokens',
            '/admin/collections',
            '/admin/collections/posts/entries',      // a list
            '/admin/collections/homepage/entries',   // a singleton → the edit form
        ];

        foreach ($surfaces as $path) {
            $body = $this->get($path, ['err' => self::MARKER, 'msg' => self::MARKER])->body;
            self::assertStringNotContainsString(
                self::MARKER,
                $body,
                "{$path} reflected attacker-controlled query text into the page (ADMIN-10).",
            );
        }
    }

    public function test_a_known_code_still_renders_its_fixed_message(): void
    {
        $this->actingAs('admin');

        // The control: a code the map knows resolves to its fixed string, so the
        // hardening didn't just silence every notice.
        self::assertStringContainsString('Role created.', $this->get('/admin/roles', ['msg' => 'created'])->body);
        self::assertStringContainsString('No such role.', $this->get('/admin/roles', ['err' => 'not-found'])->body);
    }

    public function test_no_admin_controller_reads_notice_text_from_the_query(): void
    {
        // Drift guard: only the base Controller's notice() resolver may touch
        // query('msg')/query('err'); a new controller passing raw query text to a
        // view would re-open ADMIN-10 and must fail here instead.
        $dir = \dirname(__DIR__, 2) . '/src/Admin';
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            if (basename($file) === 'Controller.php') {
                continue; // the resolver lives here, by design
            }
            $src = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression(
                "/query\(['\"](?:msg|err)['\"]\)/",
                $src,
                basename($file) . ' reads a notice code from the query directly — route it through Controller::notice() instead (ADMIN-10).',
            );
        }
    }

    public function test_no_admin_template_reflects_a_raw_flash(): void
    {
        // Drift guard: the retired template-side patterns (ucfirst($flash), the
        // $flashLabel maps) must not come back — templates render the resolved
        // {kind,message} $notice, never URL text.
        $root = \dirname(__DIR__, 2) . '/src/View/themes/nimbus/templates';
        foreach (['media', 'users', 'roles', 'tokens', 'collections', 'entries'] as $section) {
            foreach (glob($root . '/' . $section . '/*.php') ?: [] as $file) {
                $src = (string) file_get_contents($file);
                self::assertStringNotContainsString('ucfirst($flash)', $src, basename($file) . ' still reflects a raw flash (ADMIN-10).');
                self::assertStringNotContainsString('$flashLabel', $src, basename($file) . ' still uses a template-side flash map — use $notice (ADMIN-10).');
            }
        }
    }
}
