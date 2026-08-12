# The Firefly Customizer

Every template ships its **own** customizer. What a site owner can change is
decided entirely by one declarative file:

```
themes/firefly-collective/templates/{template}/options.php
```

`firefly templates init` scaffolds this file, so a new template has controls from
day one. Nothing else needs wiring: the framework engine
(`themes/firefly-collective/models/template-options.php`) reads the config and
handles registration, live preview, publish, and applying values.

## How it differs from a normal WP theme

The WordPress Customizer usually previews changes with a JS emulation of the
site. Firefly instead loads the **real site** in the Customizer iframe. When a
control changes:

1. The value is POSTed to a REST endpoint and written to a **preview** copy of
   the option (`firefly_collective_{key}_preview_{template}`).
2. The iframe reloads the actual front end.
3. The front end detects it is inside the Customizer and reads the **preview**
   value instead of the published one.
4. **Publish** promotes preview → published
   (`firefly_collective_{key}_{template}`).

So what the owner sees is the genuine page, not an approximation.

## The config file

Return an array with `sections` (optional) and `options`:

```php
<?php
return array(

    'sections' => array(
        'appearance' => array(
            'title'       => 'Appearance',
            'priority'    => 30,
            'description' => 'Colors and general look.',
        ),
    ),

    'options' => array(
        'accent_color' => array(
            'type'        => 'color',
            'label'       => 'Accent Color',
            'description' => 'Links, buttons and highlights.',
            'section'     => 'appearance',
            'priority'    => 10,
            'default'     => '#7c3aed',
            'css_var'     => '--accentColor',
        ),
    ),
);
```

A **legacy flat array** (just `key => config`, no `sections`/`options` wrapper)
is still supported — `templates/default/options.php` uses that form.

### Option fields

| Field | Purpose |
|---|---|
| `type` | `checkbox`, `color`, `text`, `textarea`, `select`, `range`, `number`, `image` |
| `label` | Control label |
| `description` | Helper text (optional) |
| `section` | A section key you declared, **or** a framework section id |
| `priority` | Order within the section (optional) |
| `default` | Value until the owner changes it |
| `choices` | `value => label` map (`select`) |
| `min` / `max` / `step` | Bounds (`range`, `number`) |
| `input_attrs` | Extra input attributes (optional) |

Framework section ids you can use without declaring them:
`firefly_collective_landing`, `firefly_collective_navigation`,
`firefly_collective_layout`.

### Getting the value onto the page

Combine as needed:

| Field | Effect |
|---|---|
| `css_var` | Emits `:root { --yourVar: value }` — use `var(--yourVar)` in your CSS |
| `unit` | Appended to a numeric `css_var` value (`'px'`, `'vh'`, `'%'`) |
| `body_class` | Adds a `<body>` class. Checkbox → bare prefix when checked; otherwise `prefix{value}` |
| `render` | `callable($value, $in_preview, $template)` run on `wp_head` for anything else |

`css_property` is accepted as a legacy alias for `css_var`.

Read a value anywhere in PHP:

```php
$accent = firefly_get_template_option( 'accent_color' );
```

## Scoping guarantees

- Controls and sections are **namespaced per template**
  (`tplopt_{template}_{key}`, `firefly_sec_{template}_{section}`), so two
  templates can use the same names without colliding.
- Only the selected template's controls and sections are visible; switching
  templates in the Customizer swaps the whole control set.
- Values are stored per template, so each template keeps its own settings.

## Design guidance

Start with what the owner genuinely needs to change — often just a color or two.
Grow from there; the same mechanism scales from a single background color to a
rich set of layout, typography and section controls. Prefer `css_var` (cheap and
declarative), reach for `body_class` when CSS needs to branch, and keep `render`
for the rare case neither can express.
