# Compatibility and deprecation policy

What a plugin author can rely on, and what can change without warning.

> **Nimbus is pre-1.0.** Until `1.0.0`, the guarantees below are intentions
> rather than promises: a `0.x` minor release may break the plugin API if a
> design turns out to be wrong. Better to break it at `0.3` than to carry a
> mistake to `1.0` and support it forever.

## The public plugin API

Only these are public. Everything else in `Nimbus\` is internal and may change
in any release, including patch releases.

| Namespace / class | What it covers |
|---|---|
| `Nimbus\Plugin\Plugin` | the interface a plugin implements |
| `Nimbus\Plugin\PluginContext` | what a plugin is handed |
| `Nimbus\Plugin\FieldTypeRegistrar` | registering field types |
| `Nimbus\Content\FieldType` | the field-type contract |
| `Nimbus\Content\FieldTypes\BaseType` | the base class field types extend |
| `Nimbus\Content\Field` | the field value object passed to a field type |
| `Nimbus\Content\UnknownFieldType`, `DuplicateFieldType` | exceptions a plugin may catch |

Explicitly **internal**, whatever their visibility: `Application`, controllers,
repositories, `Connection`, `EntryService`, `CollectionService`, `Router`,
`Request`, `Response`, `Auth`, `EventDispatcher`, `PluginLoader`. A plugin
depending on any of them will break, and that is not a bug in Nimbus.

`PluginContext` grows one capability at a time, each alongside a plugin that
needs it. New capabilities are additive and never break existing plugins.

## Versioning

[Semantic Versioning](https://semver.org). Against the **public plugin API**
above, not the whole codebase.

| Change | 0.x | 1.x+ |
|---|---|---|
| New capability on `PluginContext` | minor | minor |
| New optional field-type option | minor | minor |
| Breaking change to the public API | **minor** | major |
| Internal refactor (controllers, services, HTTP) | patch/minor | patch/minor |
| Bug fix | patch | patch |

Plugins declare their range in `composer.json`:

```json
"require": { "nimbuscms/nimbus": "^0.1" }
```

`nimbuscms/nimbus` is a `project` package that plugins depend on today. Whether
the public contracts eventually split into a separate `nimbuscms/core` package
is deferred until installing Nimbus as a dependency is a real requirement —
splitting early buys synchronisation overhead before the API has been proven.

## Deprecation

From `1.0.0`, anything public that is going away:

1. keeps working for the whole of the current major version;
2. is marked `@deprecated` in the docblock, naming the replacement;
3. triggers `E_USER_DEPRECATED` where a runtime warning is useful;
4. is listed in `CHANGELOG.md` under **Deprecated** with a migration note;
5. is removed no earlier than the next major.

Before `1.0.0`, removals may happen in a minor release, but always with a
`CHANGELOG.md` entry explaining what to do instead.

## Supported versions

| | |
|---|---|
| PHP | 8.2 and 8.3. A new PHP requirement is a minor bump pre-1.0, a major after. |
| MySQL | 8.0+ |
| Security fixes | latest minor only, until there is a release cadence worth committing to |

Plugins should test against the **lowest and current** core versions they claim
to support. `plugin-markdown` runs its matrix on PHP 8.2 and 8.3 for the same
reason.

## What is not covered

- **Database schema.** Table and column names are internal. Read content
  through services, never `nb_*` tables directly.
- **Admin HTML and CSS.** Class names and markup change freely.
- **Event payload shapes.** `CoreEvents` names are stable; payload arrays are
  not frozen yet, and events are not a plugin capability at all yet.
- **Anything reached by reflection.** Making a private thing accessible does
  not make it supported.
