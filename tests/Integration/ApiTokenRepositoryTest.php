<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Api\ApiTokenRepository;

final class ApiTokenRepositoryTest extends IntegrationTestCase
{
    private ApiTokenRepository $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new ApiTokenRepository($this->db);
    }

    public function test_a_created_token_is_prefixed_and_resolvable(): void
    {
        $plain = $this->tokens->create('My app');

        self::assertStringStartsWith('nbt_', $plain);
        $token = $this->tokens->findByPlaintext($plain);
        self::assertNotNull($token);
        self::assertSame('My app', $token->name);
    }

    public function test_only_the_hash_is_stored_never_the_plaintext(): void
    {
        $plain = $this->tokens->create('Secret');

        $stored = $this->db->selectOne('SELECT token_hash FROM nb_api_tokens')['token_hash'];
        self::assertNotSame($plain, $stored, 'the plaintext must never be persisted');
        self::assertSame(hash('sha256', $plain), $stored);
    }

    public function test_the_wrong_token_resolves_to_null(): void
    {
        $this->tokens->create('Real');

        self::assertNull($this->tokens->findByPlaintext('nbt_' . str_repeat('0', 40)));
        self::assertNull($this->tokens->findByPlaintext('not-even-prefixed'));
    }

    public function test_each_token_is_unique(): void
    {
        self::assertNotSame($this->tokens->create('A'), $this->tokens->create('B'));
    }

    public function test_touch_records_last_used(): void
    {
        $plain = $this->tokens->create('App');
        $token = $this->tokens->findByPlaintext($plain);
        self::assertNotNull($token);
        self::assertNull($token->lastUsedAt, 'a fresh token has never been used');

        $this->tokens->touch($token->id);

        self::assertNotNull($this->db->selectOne('SELECT last_used_at FROM nb_api_tokens WHERE id = :i', ['i' => $token->id])['last_used_at']);
    }

    public function test_abilities_round_trip(): void
    {
        $plain = $this->tokens->create('Scoped', ['read']);

        self::assertSame(['read'], $this->tokens->findByPlaintext($plain)->abilities);
        // No abilities stores NULL, reads back as an empty list.
        $plain2 = $this->tokens->create('Plain');
        self::assertSame([], $this->tokens->findByPlaintext($plain2)->abilities);
    }

    // ------------------------------------------------------------- lifecycle

    public function test_a_future_expiry_resolves_a_past_expiry_does_not(): void
    {
        $future = $this->tokens->create('Future', [], date('Y-m-d H:i:s', strtotime('+1 hour')));
        $past   = $this->tokens->create('Past', [], date('Y-m-d H:i:s', strtotime('-1 hour')));

        self::assertNotNull($this->tokens->findByPlaintext($future), 'a not-yet-expired token resolves');
        self::assertNull($this->tokens->findByPlaintext($past), 'an expired token is indistinguishable from invalid');
    }

    public function test_a_revoked_token_stops_resolving_and_revocation_is_terminal(): void
    {
        $plain = $this->tokens->create('Doomed');
        $id    = $this->tokens->findByPlaintext($plain)->id;

        $this->tokens->revoke($id);
        self::assertNull($this->tokens->findByPlaintext($plain), 'a revoked token does not authenticate');

        // Resume cannot bring a revoked token back — revocation wins.
        $this->tokens->resume($id);
        self::assertNull($this->tokens->findByPlaintext($plain), 'revocation is terminal');
    }

    public function test_a_paused_token_stops_resolving_and_resume_restores_it(): void
    {
        $plain = $this->tokens->create('Sleepy');
        $id    = $this->tokens->findByPlaintext($plain)->id;

        $this->tokens->pause($id);
        self::assertNull($this->tokens->findByPlaintext($plain), 'a paused token does not authenticate');

        $this->tokens->resume($id);
        self::assertNotNull($this->tokens->findByPlaintext($plain), 'resuming re-enables the same token');
    }

    public function test_status_reflects_the_lifecycle(): void
    {
        $active  = $this->tokenRow($this->tokens->create('Active'));
        self::assertSame('active', $active->status());

        $expired = $this->rowById($this->tokens->create('Expired', [], date('Y-m-d H:i:s', strtotime('-1 day'))));
        self::assertSame('expired', $expired->status());
    }

    public function test_touch_increments_the_count_and_records_the_ip(): void
    {
        $plain = $this->tokens->create('Counter');
        $id    = $this->tokens->findByPlaintext($plain)->id;

        $this->tokens->touch($id, '203.0.113.7');
        $this->tokens->touch($id, '203.0.113.8');

        $token = $this->tokens->findByPlaintext($plain);
        self::assertSame(2, $token->usedCount);
        self::assertSame('203.0.113.8', $token->lastUsedIp, 'the most recent caller IP is kept');
    }

    public function test_all_lists_every_token_active_or_not_newest_first(): void
    {
        $this->tokens->create('First');
        $doomed = $this->tokens->findByPlaintext($this->tokens->create('Second'))->id;
        $this->tokens->revoke($doomed);

        $all = $this->tokens->all();

        self::assertCount(2, $all, 'a revoked token still appears in management listings');
        self::assertSame('Second', $all[0]->name, 'newest first');
    }

    // --------------------------------------------------- role-bound principals

    public function test_principal_for_unions_explicit_abilities_with_live_role_caps(): void
    {
        $roles  = new \Nimbus\Auth\RoleRepository($this->db);
        $roleId = $roles->create('writer', ['posts:write'], false);

        // Token carries an explicit read scope AND a role that grants posts:write.
        $plain = $this->tokens->create('Hybrid', ['pages:read'], null, $roleId);
        $principal = $this->tokens->principalFor($this->tokenRow($plain));

        self::assertTrue($principal->can('pages', 'read'), 'its explicit ability survives');
        self::assertTrue($principal->can('posts', 'write'), 'unioned with the role\'s live caps');
        self::assertTrue($principal->can('posts', 'read'), 'write implies read');
        self::assertFalse($principal->can('users', 'write'), 'and nothing beyond the union');
    }

    public function test_tightening_a_role_immediately_tightens_its_tokens(): void
    {
        $roles  = new \Nimbus\Auth\RoleRepository($this->db);
        $roleId = $roles->create('broad', ['posts:write', 'pages:write'], false);
        $plain  = $this->tokens->create('Bound', [], null, $roleId);

        self::assertTrue($this->tokens->principalFor($this->tokenRow($plain))->can('pages', 'write'), 'starts with the role\'s caps');

        // Drop pages:write from the role — the change reaches the token live, with
        // no re-mint. (The token's stored abilities never held it.)
        $roles->setCapabilities($roleId, ['posts:write']);

        $principal = $this->tokens->principalFor($this->tokenRow($plain));
        self::assertTrue($principal->can('posts', 'write'), 'the still-granted cap remains');
        self::assertFalse($principal->can('pages', 'write'), 'the revoked cap is gone next resolution');
    }

    public function test_a_role_bound_token_denies_once_its_role_is_deleted(): void
    {
        $roles  = new \Nimbus\Auth\RoleRepository($this->db);
        $roleId = $roles->create('temp', ['posts:write'], false);
        $plain  = $this->tokens->create('Orphan', [], null, $roleId);

        self::assertTrue($this->tokens->principalFor($this->tokenRow($plain))->can('posts', 'write'));

        // Deleting the role nulls role_id (FK ON DELETE SET NULL). With no explicit
        // abilities, the token becomes deny-by-default — NOT a legacy read-all grant.
        $roles->delete($roleId);

        $token = $this->tokenRow($plain);
        self::assertNull($token->roleId, 'the dangling role_id is nulled, not left pointing at nothing');
        $principal = $this->tokens->principalFor($token);
        self::assertFalse($principal->can('posts', 'write'), 'a deleted role neuters the token');
        self::assertFalse($principal->can('anything', 'read'), 'and does not fail open to read-all');
    }

    public function test_a_dangling_role_id_never_resolves_to_extra_authority(): void
    {
        // A token whose role was deleted keeps only its explicit abilities.
        $roles  = new \Nimbus\Auth\RoleRepository($this->db);
        $roleId = $roles->create('gone', ['admin'], false);
        $plain  = $this->tokens->create('Explicit', ['posts:read'], null, $roleId);
        $roles->delete($roleId);

        $principal = $this->tokens->principalFor($this->tokenRow($plain));
        self::assertTrue($principal->can('posts', 'read'), 'the explicit scope stands');
        self::assertFalse($principal->can('posts', 'write'), 'the deleted admin role grants nothing');
        self::assertFalse($principal->can('users', 'write'));
    }

    /** Resolve the just-minted token to its stored record (active tokens only). */
    private function tokenRow(string $plain): \Nimbus\Api\ApiToken
    {
        $token = $this->tokens->findByPlaintext($plain);
        self::assertNotNull($token);
        return $token;
    }

    /** Find a token by its plaintext among all() — works for inactive tokens too. */
    private function rowById(string $plain): \Nimbus\Api\ApiToken
    {
        $hash = hash('sha256', $plain);
        $id   = (int) $this->db->selectOne('SELECT id FROM nb_api_tokens WHERE token_hash = :h', ['h' => $hash])['id'];
        foreach ($this->tokens->all() as $token) {
            if ($token->id === $id) {
                return $token;
            }
        }
        self::fail('token not found in all()');
    }
}
