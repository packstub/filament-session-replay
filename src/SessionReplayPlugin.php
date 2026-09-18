<?php

namespace Packstub\SessionReplay;

use BackedEnum;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Packstub\SessionReplay\Filament\Resources\ReplaySessions\ReplaySessionResource;
use Packstub\SessionReplay\Filament\Widgets\ReplayStatsWidget;
use Packstub\SessionReplay\Models\ReplaySession;
use UnitEnum;

/**
 * Session replay in a panel: records the panel's pages (with the panel's own
 * guard and workspace, which the core could not know) and adds the Sessions
 * resource. Register it twice with different options when one panel is
 * recorded and another one is where recordings are watched.
 */
class SessionReplayPlugin implements Plugin
{
    use EvaluatesClosures;

    protected bool|Closure $record = true;

    protected bool|Closure $resource = true;

    protected bool $widget = false;

    /** @var array<string, mixed>|Closure */
    protected array|Closure $properties = [];

    protected ?Closure $impersonatorUsing = null;

    protected ?Closure $userLabelUsing = null;

    protected string|UnitEnum|null $navigationGroup = null;

    protected string|BackedEnum|null $navigationIcon = null;

    protected ?int $navigationSort = null;

    protected ?string $navigationLabel = null;

    protected string $slug = 'session-replays';

    protected bool $scopeToTenant = true;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        $id = app(static::class)->getId();
        $panel = Filament::getCurrentPanel();

        // A relation manager or the action may sit in a panel that did not register the plugin: defaults then.
        if ($panel === null || ! $panel->hasPlugin($id)) {
            return app(static::class);
        }

        /** @var static $plugin */
        $plugin = $panel->getPlugin($id);

        return $plugin;
    }

    public function getId(): string
    {
        return 'packstub-session-replay';
    }

    /** Record this panel's pages. A closure gets the signed-in user: fn (?Model $user): bool. */
    public function record(bool|Closure $condition = true): static
    {
        $this->record = $condition;

        return $this;
    }

    /** Show the Sessions resource in this panel; false for a panel that is only recorded. Evaluated when the panel registers, before any request. */
    public function resource(bool|Closure $condition = true): static
    {
        $this->resource = $condition;

        return $this;
    }

    /** Add the stats widget (recordings, with errors, slow pages over the last seven days) to the panel's dashboard. */
    public function widget(bool $condition = true): static
    {
        $this->widget = $condition;

        return $this;
    }

    /**
     * Extra properties kept on every recording of this panel (the panel id is always there).
     *
     * @param  array<string, mixed>|Closure  $properties
     */
    public function properties(array|Closure $properties): static
    {
        $this->properties = $properties;

        return $this;
    }

    /** Who is impersonating, when it is not Packstub's Account Switcher: fn (): int|string|null. */
    public function impersonatorUsing(Closure $callback): static
    {
        $this->impersonatorUsing = $callback;

        return $this;
    }

    /** How a person is named in the table: fn (ReplaySession $session): ?string. */
    public function userLabelUsing(Closure $callback): static
    {
        $this->userLabelUsing = $callback;

        return $this;
    }

    public function navigationGroup(string|UnitEnum|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function navigationIcon(string|BackedEnum|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function navigationLabel(?string $label): static
    {
        $this->navigationLabel = $label;

        return $this;
    }

    public function slug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    /** In a panel with tenancy the resource lists the current workspace's recordings; false lists all of them. */
    public function scopeToTenant(bool $condition = true): static
    {
        $this->scopeToTenant = $condition;

        return $this;
    }

    public function register(Panel $panel): void
    {
        if ($this->evaluate($this->resource)) {
            $panel->resources([ReplaySessionResource::class]);
        }

        if ($this->widget) {
            $panel->widgets([ReplayStatsWidget::class]);
        }

        $panel->renderHook(PanelsRenderHook::BODY_END, fn (): HtmlString => $this->recorder());
    }

    public function boot(Panel $panel): void
    {
        //
    }

    /** The recorder's script tags for the current panel, or nothing. */
    public function recorder(): HtmlString
    {
        $panel = Filament::getCurrentPanel();

        // A render hook is global; only the panel this plugin was registered on is recorded by it.
        if ($panel === null || ! $panel->hasPlugin($this->getId()) || $panel->getPlugin($this->getId()) !== $this) {
            return new HtmlString('');
        }

        // Someone watching recordings is not recorded doing it (and a replay never ends up inside a replay).
        if (request()->routeIs(ReplaySessionResource::getRouteBaseName($panel).'.*')) {
            return new HtmlString('');
        }

        $user = Filament::auth()->user();
        $user = $user instanceof Model ? $user : null;

        if (! $this->evaluate($this->record, ['user' => $user])) {
            return new HtmlString('');
        }

        return app(SessionReplayManager::class)->recorder([
            'user' => $user,
            'tenant' => Filament::getTenant(),
            'impersonator' => $this->impersonator(),
            'properties' => ['panel' => $panel->getId()] + (array) $this->evaluate($this->properties, ['user' => $user]),
        ]);
    }

    protected function impersonator(): int|string|null
    {
        if ($this->impersonatorUsing) {
            return ($this->impersonatorUsing)();
        }

        // Packstub's Account Switcher keeps who is impersonating in the session.
        $switcher = 'Packstub\\AccountSwitcher\\AccountSwitcher';

        if (class_exists($switcher) && app()->bound($switcher) && app($switcher)->isImpersonating()) {
            return app($switcher)->impersonator()?->getAuthIdentifier();
        }

        return null;
    }

    public function userLabel(ReplaySession $session): string
    {
        if ($this->userLabelUsing) {
            $label = ($this->userLabelUsing)($session);

            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        if ($session->user_id === null) {
            return __('Guest');
        }

        $user = $session->user;

        if ($user instanceof Model) {
            foreach ([config('session-replay.viewer.user_label', 'email'), 'name', 'email'] as $attribute) {
                $label = $user->getAttribute((string) $attribute);

                if (is_scalar($label) && (string) $label !== '') {
                    return (string) $label;
                }
            }
        }

        return '#'.$session->user_id;
    }

    public function getNavigationGroup(): string|UnitEnum|null
    {
        return $this->navigationGroup;
    }

    public function getNavigationIcon(): string|BackedEnum|null
    {
        return $this->navigationIcon;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    public function getNavigationLabel(): ?string
    {
        return $this->navigationLabel;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function isScopedToTenant(): bool
    {
        return $this->scopeToTenant;
    }
}
