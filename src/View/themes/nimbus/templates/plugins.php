<?php
/**
 * Read-only view of installed plugin packages.
 *
 * @var \Nimbus\Plugin\PluginStatus[] $plugins  every discovered package
 * @var \Nimbus\Plugin\PluginStatus[] $problems the subset that need attention
 * @var list<string> $warnings deployment misconfiguration warnings (may be empty)
 */
use Nimbus\Plugin\PluginStatus;
use Nimbus\View\View;

$e = static fn (?string $v): string => View::e($v);

$badgeClass = static fn (PluginStatus $p): string => match ($p->state) {
    PluginStatus::HEALTHY  => 'nb-badge-ok',
    PluginStatus::DISABLED => 'nb-badge-muted',
    default                => 'nb-badge-danger',
};
?>
<div class="nb-page-head">
    <h1>Plugins</h1>
</div>

<p class="nb-muted" style="margin:-8px 0 20px;max-width:60ch">
    Everything Composer has installed as a NimbusCMS plugin. This page is a
    diagnostic view, not an installer — plugins are added and removed with
    <code>composer require</code> and <code>composer remove</code>, and enabled
    or disabled in <code>config/plugins.php</code>.
</p>

<?php foreach ($warnings as $warning): ?>
    <div class="nb-alert nb-alert-warn"><?= $e($warning) ?></div>
<?php endforeach; ?>

<?php if ($problems !== []): ?>
    <div class="nb-alert nb-alert-error">
        <?= count($problems) === 1 ? 'One plugin needs' : count($problems) . ' plugins need' ?>
        attention — see the highlighted <?= count($problems) === 1 ? 'row' : 'rows' ?> below.
    </div>
<?php endif; ?>

<?php if ($plugins === []): ?>
    <div class="nb-empty-panel">
        <span class="nb-empty-ic">⚡</span>
        <h2>No plugins installed</h2>
        <p>Install one with <code>composer require vendor/plugin</code>. Official
           plugins live under the <code>nimbuscms</code> organization.</p>
    </div>
<?php else: ?>
    <div class="nb-table-wrap">
        <table class="nb-table">
            <thead>
                <tr>
                    <th>Plugin</th>
                    <th>Package</th>
                    <th>Version</th>
                    <th>Provider</th>
                    <th>State</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plugins as $p): ?>
                    <tr<?= $p->isProblem() ? ' class="nb-row-danger"' : '' ?>>
                        <td>
                            <strong><?= $e($p->displayName) ?></strong>
                            <?php if ($p->id !== ''): ?>
                                <div class="nb-muted"><code><?= $e($p->id) ?></code></div>
                            <?php endif; ?>
                        </td>
                        <td><code><?= $e($p->packageName) ?></code></td>
                        <td class="nb-muted"><?= $e($p->version) ?></td>
                        <td>
                            <span class="nb-badge <?= $p->official ? 'nb-badge-official' : 'nb-badge-muted' ?>">
                                <?= $e($p->providerLabel()) ?>
                            </span>
                        </td>
                        <td>
                            <span class="nb-badge <?= $badgeClass($p) ?>"><?= $e($p->stateLabel()) ?></span>
                            <?php if ($p->message !== ''): ?>
                                <div class="nb-muted nb-plugin-msg"><?= $e($p->message) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
