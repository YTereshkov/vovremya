# Frontend design system

The approved mockups remain the visual source of truth. The application shell
uses these primary references:

- `01-mobile-сегодня.png` for mobile header, agenda cards, and bottom navigation;
- `26-desktop-календарь-день.png` for desktop sidebar and content proportions;
- `10-mobile-настройки.png` for grouped surfaces and form density;
- `32-mobile-клиенты-список.png` for list spacing and selected navigation states.

## Tokens

- Background: cool near-white with restrained sage radial light.
- Primary: indigo `#3431d8`; selected surface `#ececfc`.
- Text: deep navy `#101827`; secondary text `#495b73`.
- Border: cool gray `#d6dddc`.
- Semantic statuses: green `#008f68`, orange `#df6500`, red `#f01822`.
- Surfaces: translucent white, thin border, 16 px radius, minimal shadow.
- Typography: Inter-compatible system sans serif with compact tracking on headings.
- Icons: Lucide outline icons at 1.8 stroke width where they match the mockups.

## Responsive shell

The same routes and content power both layouts. Below the large breakpoint the
shell uses a fixed five-item bottom navigation. At the large breakpoint it uses
a 230 px left sidebar. Desktop-only attention panels do not appear on mobile.

Parts 8–10 use presentation fixtures only to verify the approved visual system.
They do not define scheduling business rules.
