# Masking

The core masks every input by default and password inputs always, along with text typed into rich editors (RichEditor, MarkdownEditor: anything `contenteditable`) and the value of hidden inputs, so what people type stays out of a recording. TagsInput shows its tags as text, not in an input; mask it with `->maskInReplay()` when the tags are personal. What a page *displays* (an IBAN in a table, a salary in an infolist, a whole billing section) is yours to decide, and the plugin lets you say it where the field is defined.

| Macro | In the recording | Renders |
| --- | --- | --- |
| `->maskInReplay()` | The text is replaced with asterisks of the same length; the layout stays. | `data-replay-mask="true"` |
| `->blockInReplay()` | The element is recorded as an empty box of the same size. | `data-replay-block="true"` |

Both take an optional bool, so a condition fits on one line: `->maskInReplay(! app()->isLocal())`.

## Where they work

| On | Attribute goes on | Covers |
| --- | --- | --- |
| Form fields (`Filament\Forms\Components\Field` and everything that extends it) | The field wrapper | Label, hint, helper text, validation message and the value |
| Table columns (`Filament\Tables\Columns\Column`) | The cell | The cell's content in every row |
| Infolist entries (`Filament\Infolists\Components\Entry`) | The entry wrapper | Label and value |
| Layout components (`Section`, `Fieldset`, `Grid` and other schema components with extra attributes) | The component itself | Everything inside |

## Examples

```php
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

Section::make('Billing')
    ->blockInReplay()
    ->schema([
        TextInput::make('iban'),
        TextInput::make('vat_number'),
    ]);

TextInput::make('email')->maskInReplay();
```

```php
use Filament\Tables\Columns\TextColumn;

TextColumn::make('email')->maskInReplay();
TextColumn::make('salary')->money('EUR')->blockInReplay();
```

```php
use Filament\Infolists\Components\TextEntry;

TextEntry::make('national_id')->maskInReplay();
```

Mask keeps the page readable for whoever watches (you still see that a value is there and how long it is); block is the stronger choice for whole areas, images, charts and anything where even the shape says too much.

## Outside the macros

The macros only add the core's attributes, so anything the macros do not reach works the same way by hand:

```blade
<div data-replay-mask>{{ $customer->iban }}</div>
<div data-replay-block>…</div>
```

In a custom Filament view, column or widget, put the attribute on your own element. `data-replay-ignore` (no input events recorded for an element), the selectors behind the attributes and `privacy.mask_all_text` for layout-only recordings are described in the core's [Privacy guide](https://github.com/packstub/session-replay/blob/main/docs/privacy.md#mask-block-ignore).

Masking happens in the browser, before anything is uploaded: masked text and blocked content never reach your server's recordings. It applies to recordings made after the change; existing recordings keep what they recorded.
