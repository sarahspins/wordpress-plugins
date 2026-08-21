# Ice & Field Programming Changelog

## 1.9.1.16

- Allows active Dash rows with an unmapped source Sport to be selected when importing.
- Uses the confirmed Bulk classification Sport to resolve those rows.
- Shows a clear validation message if an unmapped row is submitted without a Sport selection.
- Imports Camp Programs directly under their Season without creating or assigning a duplicate WordPress Level.
- Renders existing unlevelled Camp Programs directly in public shortcodes without a fallback Level heading.
- Sorts Camp offerings chronologically by start date, earliest first.
- Shows each Camp's date range and time directly on its public offering card.
- Uses the final assigned Dash event as a Camp's end date instead of the Season or registration-close boundary.
- Treats Friday-to-Monday Camp occurrences as consecutive workdays rather than separate weeks.

## 1.9.1.15

- Adds editable Sport, Format, and Category guesses to the protected Season import preview.
- Applies the confirmed classification in bulk to the Season and every selected Level and Program.
- Makes all three classification taxonomies available on Seasons, Levels, and Programs.
- Uses existing Season classifications as the preferred defaults when refreshing an imported Season.
- Detects daily versus weekly sessions from the spacing of their Dash events and uses the correct unit in import previews and public pricing.

## 1.9.1.14

- Adds the monorepo Update URI and participates in the Dash Connector-managed private release updater.

## 1.9.1.13

- Exposes synchronized Level and Program IDs to companion plugins after a clean protected sync.
- Adds a Production companion preview supplied by Productions for Production-designated Seasons.
- Adds an opt-in Production participant synchronization choice.
- Reports Production Division and Group projection counts in the protected sync completion notice.
- Keeps non-Production Season synchronization unchanged.

## 1.9.1.12

- Added reciprocal Production Division and Group relationship columns to the Levels and Programs admin lists.
- Supports verification of the protected Stage 3 Productions alignment migration without changing Programming sync behavior.

## 1.9.1.11

- Added an explicit Production designation and optional linked Production relationship to Seasons.
- Added Production alignment status to the Seasons admin list.
- Added the `ifprog_after_successful_sync` action after a clean protected Dash synchronization.
- Kept all current Programming and Productions import behavior unchanged during the compatibility stage.

## 1.9.1.10

- Grouped Season Discovery into New, Needs Re-sync, Imported, and Never Import sections, with New Seasons first.
- Added a prominent prompt when newly discovered Seasons are ready to review and import.
- Added protected three-way description syncing for Seasons, Levels, and classes.
- Allows a description removed in Dash to clear the previously imported Dash copy while preserving anything added or edited in WordPress.
- Applies the same protection to generated class excerpts.

## 1.9.1.9

- Uses the exact existing League-style Coming Soon button and configured Coming Soon text for class-based Upcoming Seasons.
- Keeps the full registration opening date in the Season details instead of repeating a shortened date inside the button.
- Prevents Coming Soon status buttons from shifting vertically on hover.

## 1.9.1.8

- Generalized the existing League Coming Soon treatment to every Upcoming Season, including class-based Seasons that already contain Levels and Programs.
- Uses a muted, non-clickable `Registration opens Aug/24` Season button when a registration opening date is available.
- Falls back to the configured Coming Soon label when the opening date is not known.
- Prevents an Upcoming Season from exposing an active Season registration shortcut before its effective opening date.

## 1.9.1.7

- Grouped time-enabled Class Offerings beneath shared time or time-range labels instead of repeating a time after every Level.
- Sorted each day's time groups from earliest to latest, with Time TBD last.
- Kept the default no-time Class Offerings output unchanged.

## 1.9.1.6

- Added optional times to compact Class Offerings with `show_times="yes"`; times remain hidden by default.
- Keeps Levels sharing the same time compressed into ranges and separates multiple time slots for the same Level.
- Displays Time TBD when a filtered offering has no dependable time value.

## 1.9.1.5

- Added dual Drop-In pricing such as `$30 per class` or `$90 for remaining 3 weeks`.
- Recalculates the remaining package price from the imported session total, class count, weekday schedule, and dates still remaining.
- Shows only the per-class rate when a dependable remaining-date count is unavailable.
- Kept the two Drop-In choices compact and right-aligned on desktop and mobile.

## 1.9.1.4

- Hid classes from Current Season accordions once their last scheduled weekday has passed.
- Kept mixed Levels visible with only their remaining classes and removed Levels that no longer contain any upcoming class dates.
- Preserved classes with uncertain schedule data and the specially routed Adult Development Camp drop-in instead of hiding them speculatively.
- Added a `hide_finished="no"` catalog escape hatch for archival or informational pages.
- Displayed paid one-session offerings as Per Class with their single-class rate and no misleading one-week duration.

## 1.9.1.3

- Matched compact Class Offerings to the website's exact `#7a7a7a` body-copy color.
- Sorted each day's Class Offerings by Sport—Figure Skating, then Hockey, then other sports—and by starting age from youngest to oldest within each Sport.
- Retained Level display order and natural title order as tie-breakers when Sport and age ranges match.

## 1.9.1.2

- Excluded Programs belonging to Show-named Seasons from compact Class Offerings summaries by default.
- Added an `include_show="yes"` escape hatch for pages that intentionally need those offerings.
- Combined numbered labels with a shared “for Hockey” suffix into ranges such as Snowplow Sam 1–3 for Hockey.
- Made the complete offering summary, including bold labels, inherit the website's surrounding default text color.

## 1.9.1.1

- Added `[if_programming_offerings]` and its `[if_class_offerings]` alias for compact day-by-day Level summaries.
- Added Current, Upcoming, combined, and specific-Season selection plus the existing Sport, Format, Category, Program Group, Level, and registration-state filters.
- Combines matching Seasons by lifecycle by default, so regular and Specialty Seasons can share one Current or Upcoming day-by-day list.
- Automatically compresses consecutive numbered Levels into labels such as Snowplow Sam 1–4 and Basic 1–6.
- Shows unrecognized schedules under Schedule TBD by default so incomplete source information is not silently hidden.

## 1.9.1

- Added a Monitoring screen with a global pause/readiness control, planned check frequency, discovery-signal preferences, notification address, and activity-retention setting.
- Added configurable Season families with independent monitoring switches and plain-language Dash Season name matching rules.
- Added a bounded local Activity Log for monitoring configuration, manual discovery refreshes, Never Import exclusions/restorations, protected sync results, warnings, and failures.
- Kept the release intentionally quiet: no scheduled Dash requests, email, background sync, automatic import, deletion, unpublishing, or protected-presentation replacement was introduced.

## 1.8.6

- Extended the opt-in Dash presentation controls to Season and Level names and descriptions.
- Grouped all six Season, Level, and class choices into a compact optional-update panel.
- Made Season presentation updates available for empty Season-only imports.
- Kept blank descriptions non-destructive and added per-layer update counts to the completion notice.

## 1.8.5

- Added opt-in sync controls to update selected class names from current Dash Team names.
- Added an independent option to replace selected class content and excerpts with non-empty Dash Team descriptions.
- Kept both replacements off by default and reported their actual update counts after synchronization.

## 1.8.4

- Free one-off Programs now show their exact event date and time in the schedule column.
- Removed the separate duration column from those rows along with the $0 price information.

## 1.8.3

- Free one-day Programs now omit the Session Price label and $0 value.
- Replaced the misleading “for 1 week” duration with an italic “for one day” label.

## 1.8.2

- Added a Season Shortcode box to every Season edit screen.
- Uses the stable WordPress Season post ID so the shortcode survives title and slug changes.
- Added a one-click Copy Shortcode button with accessible confirmation and clipboard fallback.

## 1.8.1

- Automatically loads cached Season discovery data when the admin screen opens.
- Automatically performs the comparison pass when discovery cache data is unavailable.
- Renamed the Changed state to the clearer Needs Re-sync flag and added a prominent summary alert.
- Added Season checkboxes, Select All/Deselect All controls, and an Exclude Selected bulk action.
- Kept explicit Refresh Discovery as the force-fresh option while automatic loading uses the Connector cache.

## 1.8.0

- Added the Season Discovery Inbox with direct Current and Upcoming Season listings.
- Added New, Imported, and Changed indicators with Preview / Import, Sync, and Review Changes actions that open the protected workflow.
- Added compact successful-sync snapshots and summaries for Season, Level, and class additions, removals, and meaningful source changes.
- Added an All Seasons view with Current, Upcoming, Completed, Imported, New, Changed, and Excluded filters.
- Added reversible Never Import exclusions keyed to immutable Dash Season IDs.
- Added WordPress publication state, lifecycle, last sync, last check, and protected-local-field visibility to imported Season rows.
- Kept discovery fully manual; no scheduled requests, imports, deletions, or publication changes were introduced.

## 1.7.37

- Added a maintained 1.8–2.0 roadmap for Season discovery, exclusions, guarded monitoring, email review links, and opt-in automatic importing.
- Added explicit Season routing for Dash League #86 and its linked Programs.
- Keeps the drop-in Level with only the published Current Learn to Play Season because its registration link already exposes future dates.
- Preserves the last local assignment when no Current Learn to Play Season is temporarily available, instead of moving the Level into an Upcoming or unrelated Dash Season.
- Safely pauses a first-time #86 import when no published Current Learn to Play Season is available.
- Added the routing decision to the Dash preview and Level editor for visibility.

## 1.7.36

- Expanded Coming Soon Season bubbles from standalone League imports to every upcoming League-format Season.
- Added matching Coming Soon bubbles to every upcoming League-format Level, including normal Team-backed Levels.
- Detects League format from both Season classification and the Programs contained by each Level.
- Left Class, Camp, Clinic, Drop-In, and other non-League displays unchanged.

## 1.7.35

- Added non-clickable Coming Soon bubbles to upcoming standalone League Season headers.
- Added matching Coming Soon bubbles to their standalone Level headers.
- Reused the configurable Coming Soon label and existing status styling.
- Kept the status bubbles in the same responsive right-aligned positions as the Register Now shortcuts they automatically replace when registration opens.

## 1.7.34

- Added standalone Dash League rows to Preview & Sync when a League has no Team/class records.
- Made standalone League rows selectable with the existing Select All and Deselect All controls.
- Imported a selected standalone League as a real Season-linked Level without creating a placeholder Program.
- Preserved the League description, age range, and broader DaySmart Level registration destination.
- Displayed published standalone Levels in the public catalog, including Sport, Format, Category, Group, Level, Season, and registration-status filtering.
- Added Level and Season registration shortcuts for open standalone Levels while retaining automatic Coming Soon and Registration Closed behavior.

## 1.7.33

- Registered Program Categories for both Levels and individual Programs/classes.
- Made public category filters match categories assigned directly to a Program or inherited from its linked Level.
- Added built-in Homeschool and Adaptive Program Categories.
- Backfilled Homeschool onto existing Programs whose current or original Dash Team name begins with HS.
- Automatically assigns Homeschool to matching future Dash imports.
- Assigns Adaptive once to a linked Adaptive Level so every class under it inherits the category; unlinked Adaptive Programs are categorized directly.
- Preserved all existing manual Program and Level category assignments.
- Added Program Categories to the Levels admin list and identified Level-inherited categories in the Programs list.
- Added Homeschool and Adaptive shortcode recipes to the Programming dashboard and documentation.

## 1.7.32

- Removed the doubled header-bottom and panel-top spacing from every expanded Season.
- Applied the tighter seam whether the first content is a description, Program Group, Level, or class list.
- Preserved the normal gaps between items inside the expanded Season.

## 1.7.31

- Added each Season's WordPress description to the top of its expanded public catalog contents.
- Added a Show Season descriptions setting alongside the existing Group, Level, and class description controls.
- Kept Season descriptions hidden while their accordion is collapsed.
- Matched the existing description typography, content width, and section spacing.
- Enabled Season descriptions by default without requiring a settings migration.

## 1.7.30

- Added an editable Display Order field to Season Details.
- Kept Seasons chronological by start date and then end date.
- Applied lower Display Order values first only when both Season dates match.
- Retained the Season title as the deterministic fallback when order values also match.
- Preserved the existing rule that completed Seasons remain grouped at the bottom.
- Added a Display Order column to the Seasons admin list.
- Defaulted existing Seasons to order 100 without requiring a migration.

## 1.7.29

- Corrected whole-year age labels by accounting for Dash's minimum and maximum age-month fields.
- Rounded starting ages upward and ending ages downward so displayed ranges do not include ineligible ages.
- Applied the correction immediately to untouched imported Levels while preserving manually customized age ranges.
- Sorted Levels within every Program Group by youngest age, then oldest age, with existing order retained for ties.
- Removed the automatic tint from Program Group headers so the selected background fills both collapsed and expanded areas consistently.
- Added independent border colors and widths for Program Groups, Levels, and compact class dividers.
- Added separate corner-radius controls for Seasons, Program Groups, Levels, and classes.

## 1.7.28

- Added an independent Season Border color setting.
- Added a Season Border Width setting from 0 to 8 pixels.
- Limited both settings to the outer Season outline without changing inner divider, Program Group, Level, or class borders.
- Initialized the new Season Border color from the previous 48% Accent and 52% Borders blend to preserve the existing appearance.

## 1.7.27

- Extended the selected Season background color across both the Season heading and its complete expanded content area.
- Added separate Level Background and Class Background color settings.
- Retained a separate Program Group background color.
- Initialized the new Level and Class colors from the previously saved shared surface color to preserve existing appearance.
- Mixed class hover highlighting into the selected Class background instead of replacing it.

## 1.7.26

- Removed the outer catalog border, background, corner treatment, and internal padding on desktop and mobile.
- Added an independent Season Corner Radius setting.
- Kept the existing corner-radius control for Program Groups and Levels.
- Renamed Catalog Background to Expanded Session Background to reflect its remaining use.

## 1.7.25

- Added a Program Categories column to the Programs admin list.
- Displayed assigned categories as links for quickly narrowing the Programs list.
- Highlighted Programs without a category using a clear Not assigned marker.

## 1.7.24

- Added separate Display Settings for spacing above and below the complete public Programming catalog.
- Applied the new outer spacing consistently to every shortcode catalog on desktop and mobile.
- Preserved existing layouts by defaulting both new spacing controls to zero.

## 1.7.23

- Removed the extra Other Programs accordion from the public catalog.
- Displayed Levels without a Program Group directly beneath all defined Program Group accordions.
- Preserved each unassigned Level as its normal collapsible Level with its description, classes, and registration shortcut.
- Kept defined Program Groups in their configured order and unassigned Levels afterward.
- Applied the configurable Registration Closed label to completed Season status bubbles.

## 1.7.22

- Sorted public class rows by weekday from Monday through Sunday.
- Sorted classes on the same weekday by their starting time.
- Applied the same schedule order to direct class lists, Program Groups, and Levels.
- Placed unrecognized weekdays after scheduled weekdays and unrecognized times after timed classes on the same day.
- Retained Program page order and title as deterministic fallbacks when schedules match.

## 1.7.21

- Matched Sport and Format shortcode values against both stored term slugs and normalized visible term names.
- Restored existing hockey classes whose legacy Hockey term uses the abbreviated slug `h`.
- Allowed `sport="hockey" format="class"` to include matching current and completed class Programs.
- Kept matching empty Upcoming hockey League Seasons in Sport-only catalogs.
- Changed future imports to assign real term IDs instead of treating classification slugs as new term names.
- Preserved existing taxonomy terms and relationships without requiring re-import or manual retagging.

## 1.7.20

- Added Sport and Format classification directly to Seasons as well as Programs.
- Classified Season-only imports from available Dash fields and conservative Season-name matching.
- Included published empty Upcoming Seasons when `sport` or `format` shortcode filters match.
- Added a display-time fallback for already imported Seasons, so they do not need to be imported again.
- Kept Program Category, Program Group, and Level filters strict until matching Program records exist.
- Continued showing empty Upcoming Seasons without registration links as compact Coming Soon rows.

## 1.7.19

- Hardened the Season-only and static Season display changes after a site-specific upgrade failure.
- Replaced template-level loop continuation with an explicit static-or-accordion rendering branch.
- Changed empty Upcoming Season discovery to a lower-memory ID-only query.
- Validated every returned Season before adding it to the public catalog.
- Released temporary unfiltered Program query results before rendering.

## 1.7.18

- Added a dedicated Season-only import when a Dash Season has no Team or Level records.
- Kept Season-only imports draft-first with an explicit option to publish the Season immediately.
- Prevented an empty Team selection from being mistaken for a Season-only import.
- Included published empty Upcoming Seasons in unfiltered public catalogs.
- Displayed empty Upcoming Seasons as compact, non-expandable rows with dates and a non-clickable Coming Soon status.
- Automatically returned a Season to the normal accordion display once published Programs are available.

## 1.7.17

- Replaced completed Season accordions with compact, non-expandable rows.
- Reduced completed rows to the Season name and the non-clickable Registration Closed status.
- Lightened completed Season heading text for clearer visual hierarchy.
- Continued sorting completed Seasons at the bottom of the catalog.

## 1.7.16

- Added a muted Registration Closed status pill to closed Season headers.
- Changed closed Season heading text to dark grey.
- Sorted closed Seasons after open and upcoming Seasons while retaining date order within each group.
- Kept the closed status non-clickable and continued suppressing registration links after registration closes.

## 1.7.15

- Added an optional Publish imported records immediately checkbox to Dash Sync.
- Published selected Programs, their parent Levels, and the Season in the same import when explicitly selected.
- Allowed previously imported drafts to be published by selecting them again with the publish option.
- Kept draft-first imports as the unchecked default.
- Continued preserving publication status during ordinary refreshes and never unpublished existing records.
- Added the number of records published to the import result notice.

## 1.7.14

- Allowed multiple Seasons to be marked Current without changing other active Seasons to Completed.
- Updated current-season shortcode filtering to return every published Current Season.
- Updated the Programming Dashboard to display every Current Season.
- Included Current, Upcoming, and Completed Seasons in the default catalog.
- Automatically treated Upcoming Seasons as Current once registration opens.
- Recovered Seasons incorrectly marked Completed by the former single-Current limitation when their registration window is still open.
- Changed the public registration line to “Registration opened on” after the opening date passes.
- Automatically replaced registration dates with a closed-date message after registration ends.
- Removed Season, Level, and class registration links from closed or Completed Seasons.
- Added Seasons-screen prompts to mark automatically opened Seasons Current or overdue Seasons Completed.

## 1.7.13

- Seeded editable starter Program Categories so the WordPress checklist is no longer blank.
- Added Learn to Skate, Learn to Play, Specialty Classes, and Camps & Clinics starter categories.
- Changed the default catalog to include Current and Upcoming Seasons.
- Added comma-separated Season selectors while keeping explicit current-only and all-season shortcode controls.
- Renamed the Programming submenu item from Categories to Program Categories.

## 1.7.12

- Moved the configurable accent stripe from individual classes to their parent Level.
- Changed class cards into a continuous compact list with simple row separators.
- Applied the flatter Level-and-list treatment on both desktop and mobile.
- Kept the accent and registration buttons synchronized with their configured hover color.

## 1.7.11

- Hid the Session Price label in the phone layout.
- Placed the price and week count beside the Day & Time value on mobile.
- Reduced the vertical height of mobile class cards without changing desktop pricing.

## 1.7.10

- Right-aligned the complete Session Price block, including its label, price, and week count.
- Kept the right alignment in the responsive phone layout.

## 1.7.9

- Restored Register Now buttons on individual open class rows.
- Linked each class button directly to its DaySmart Team registration page.
- Kept Level buttons linked to their broader League/Level registration destination.
- Preserved manual class registration-link overrides.
- Retained the compact single-line session price and week count.

## 1.7.8

- Shifted Level registration shortcuts farther right for clearer separation from their accordion chevrons.
- Left Season shortcut positioning unchanged.

## 1.7.7

- Aligned Season shortcut buttons vertically with their accordion chevrons.
- Moved Season and Level shortcut buttons to the right edge of their rows.
- Removed repeated Register Now buttons from individual open class rows.
- Kept closed and coming-soon status badges on the affected classes.
- Displayed session price and italicized week count together on one line.

## 1.7.6

- Added Register Now shortcuts to collapsed Season and Level accordions.
- Linked Season shortcuts to the selected DaySmart Program and Season catalog.
- Reused the DaySmart Level destination for Level shortcuts.
- Added optional manual Registration URL fields to Seasons and Levels.
- Kept shortcut buttons hidden unless at least one displayed class is open.
- Opened all shortcut destinations in a new browser tab.

## 1.7.5

- Opened public Register Now destinations in a new browser tab.
- Added safe external-link relationship attributes.

## 1.7.4

- Imported the Dash Team class count as the Program's session length in weeks.
- Displayed “for 8 weeks,” “for 7 weeks,” and similar context beneath public session prices.
- Added an editable Session Length (Weeks) field for manual adjustments.

## 1.7.3

- Matched each class row's accent stripe to its Register Now button color.
- Changed both the stripe and button to their configured hover colors when the whole class row is hovered or receives keyboard focus.

## 1.7.2

- Kept Register Now buttons stationary while their hover colors change.

## 1.7.1

- Added Select all and Deselect all controls above the Dash Sync preview.
- Added a live count of the Team classes selected for import.
- Limited bulk selection to eligible Team rows.
- Added normal and hover background/text color settings for Register Now buttons.
- Defaulted the Register Now hover background to Ice & Field orange.

## 1.7.0

- Added automatic DaySmart registration links for imported Team classes.
- Reused the proven Productions deep-link format based on the Team's parent League ID.
- Included the Connector company and Team facility ID in every generated registration destination.
- Added registration-link readiness to the Dash preview before import.
- Kept manually entered Program registration URLs protected from later Dash refreshes.
- Continued showing registration buttons only while the Program's registration state is open.

## 1.6.0

- Allowed each Level to belong to multiple Program Groups.
- Added checkbox-based Group selection to the Level editor.
- Repeated a Level and its classes beneath every assigned Group in the public catalog.
- Updated Group filtering and Level-list visibility for multi-Group assignments.
- Continued preserving all local Group assignments during Dash imports and refreshes.

## 1.5.1

- Corrected WordPress taxonomy navigation labels so Program Group screens return to Program Groups instead of Categories.
- Added explicit parent and empty-list labels for all Programming taxonomies.

## 1.5.0

- Added reusable Program Groups between Seasons and Levels.
- Added starter Groups for Basic Skills, Adult, Free Skate, Snowplow Sam, and Hockey.
- Added a single Program Group selector to every Level.
- Added editable Group descriptions and numeric Display Order, with lower numbers appearing first.
- Added the collapsed Program Group layer to the public catalog.
- Kept unassigned Levels visible under an Other Programs group displayed last.
- Added Program Group admin filtering, Level-list visibility, shortcode filtering, heading-size settings, and description controls.
- Preserved all local Group assignments during Dash imports and refreshes.

## 1.4.2

- Simplified the Season registration window to dates only.
- Removed bold registration labels and styled the complete line in italic.

## 1.4.1

- Added the Season registration opening and closing dates beneath the Season date range.
- Included registration times using the site’s configured date and time formats.
- Added a `show_registration_dates` shortcode control and a stacked phone layout.
- Gracefully displays either boundary when only an opening or closing date is available.

## 1.4.0

- Added Session accordions as the public catalog’s first layer.
- Kept Sessions and Levels collapsed by default, with shortcode controls for either layer.
- Moved Level descriptions inside expanded Level panels.
- Replaced large per-class accordions with compact rows for class name, day and time, full-session price, and registration action or status.
- Preserved distinct class descriptions while suppressing duplicated Level text.
- Added responsive tablet and phone layouts.
- Added settings for catalog colors, display density, spacing, corner radius, type sizes, and description visibility.
- Restyled the catalog as a restrained light surface that fits the existing Ice & Field Learn to Skate page.

## 1.3.1

- Added a self-contained light background and readable text colors to the public catalog.
- Converted Level groups into native accessible accordions.
- Collapsed all Levels by default, with an optional `open_levels` shortcode control.
- Moved each Level description directly beneath its name in the collapsed header.
- Kept Team/Class accordions nested inside their parent Level.
- Kept distinct Program descriptions visible while suppressing League text duplicated by older flat imports.

## 1.3.0

- Added Level records as the missing middle layer between Seasons and Programs.
- Mapped each selected Team’s parent Dash League to one Season-linked Level.
- Linked imported and refreshed Programs to their Level without creating duplicates.
- Added Level management, Season and Program relationships, list columns, and admin filters.
- Grouped public Program accordions beneath Level headings by default.
- Added `level` and `group_levels` shortcode controls.
- Preserved existing flat Level labels as a fallback for older and manual Programs.

## 1.2.1

- Fixed a Dash Preview regression that could replace the availability lookup table with one row’s display text.
- Restored Team registration status, enrollment, capacity, and waitlist matching throughout the preview.

## 1.2.0

- Added an administrator-triggered protected import for selected Dash Teams.
- Created new Seasons and Programs as drafts; nothing is automatically published or deleted.
- Added duplicate-safe source linking so later imports refresh the same WordPress records.
- Preserved local titles, descriptions, excerpts, publication status, ordering, taxonomies, registration links, and button text.
- Imported source dates, schedule, age range, level, full-session price, availability, enrollment, capacity, waitlist, and registration state.
- Kept scheduled synchronization disabled for the next roadmap milestone.

## 1.1.1

- Changed Dash Preview pricing to show the full session price.
- Multiplied the Product’s per-class price by the Team’s class count.
- Kept flat-fee Products as a single session price.
- Added the calculation beneath each total, such as `$180` with `8 classes × $22.50`.

## 1.1.0

- Completed live Dash discovery for the Season → League → Team hierarchy.
- Confirmed that Team records represent the actual registrable class choices.
- Mapped Season signup dates, Team schedules and status, League levels and ages, Product price sources, and batched Team Registration Info.
- Added a Season-selectable, read-only Dash import preview.
- Added proposed create/update, Sport, Format, schedule, availability, and price-source review.
- Added direct sport mapping for Dash Figure Skating and Ice Hockey records.
- Kept the preview non-destructive: no Seasons or Programs are created or updated.

## 1.0.0

- Created a standalone Programming module for the main Ice & Field website.
- Added local Season records with Draft, Upcoming, Current, Completed, and Archived lifecycle states.
- Added Program records for classes, leagues, camps, clinics, drop-ins, and custom categories.
- Added Figure Skating and Hockey Sports plus Class, League, Camp, Clinic, and Drop-In Formats.
- Added automatic Coming Soon, Registration Open, and Registration Closed calculation.
- Added Season-level registration-date fallback for Programs without their own dates.
- Added manual status overrides and separate WordPress publication control.
- Added native accessible accordion displays using `[if_programming]` and `[if_programs]`.
- Added shortcode filters for Sport, Format, Program Category, Season, and registration state.
- Added display settings for labels, button text, colors, and empty results.
- Added a Programming dashboard with current Season and registration-state summaries.
- Added a thin dependency boundary around Ice & Field Dash Connector v1.3.0 or newer.
- Added separate Dash source values and local override protection for future imports.
- Added a Dash Connection roadmap without enabling automatic synchronization.
