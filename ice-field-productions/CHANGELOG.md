# Changelog

## 3.7.1 — Communication Center Completion
- Added Media Library attachments with a five-file limit, 10 MB per-file limit, 20 MB combined limit, and permission, readability, and WordPress file-type validation.
- Added reusable email sender name, reply-to address, accent color, and footer settings, while allowing sender and reply-to overrides for an individual message.
- Added safe retry controls that resend only failed recipient handoffs and preserve already successful results.
- Added a provider-event integration API and history indicators for authenticated delivery, bounce, and open events without inferring those outcomes from `wp_mail()`.
- Renumbered the next planned Controlled Two-Way Dash Updates milestone to 3.8 so the roadmap follows the current plugin release sequence.

## 3.7.0 — Programming-Only Production Synchronization
- Removes the temporary legacy full-Production importer, its administrator route, and its protected form handler.
- Removes the one-release recovery button from unaligned Production records.
- Directs both aligned and unaligned Dash-linked Productions to Programming for Season discovery and synchronization.
- Retains manual Productions, Dash linkage and relinking, registration-link helpers, the Alignment Audit, Team Discovery, and targeted participant recovery.

## 3.6.5 — Private GitHub Updates
- Adds the monorepo Update URI and participates in the Dash Connector-managed private release updater.

## 3.6.4 — Formal Plugin Dependencies
- Declares Ice & Field Dash Connector and Ice & Field Programming as required WordPress plugins.
- Lets WordPress enforce the Connector → Programming → Productions activation and deactivation order.
- Raises the minimum WordPress version to 6.5, where native plugin dependency handling is available.

## 3.6.3 — WordPress Page Pickers for URL Fields
- Adds a native-style WordPress Page dropdown alongside URL fields throughout Productions editors and guided tools.
- Populates the associated URL with the selected page permalink while keeping the URL input editable for external destinations.
- Covers Production, Division, Group, Sponsor, Resource, Important Date, Homepage Builder, and Guided Production Setup URL fields.
- Leaves the centralized Site Links page unchanged because it already stores a WordPress Page and custom external URL separately.

## 3.6.2 — Automatic Season Registration Link Projection
- Copies a Production-designated Programming Season's effective registration URL into its companion Production automatically.
- Uses the protected manual Programming URL when one exists and otherwise uses Programming's generated Dash Season registration URL.
- Preserves a manually customized Production registration URL using the existing source-aware override protection.

## 3.6.1 — Media Library URL Pickers
- Adds native WordPress Media Library pickers for Production trailers and digital program PDFs.
- Adds file pickers for rehearsal schedules, costume information, and program advertising resources.
- Keeps every field editable so externally hosted URLs such as YouTube, Vimeo, Google Drive, or other websites remain supported.
- Reuses the existing WordPress media modal and stores the selected attachment URL without changing public templates or existing saved values.

## 3.6.0 — Programming-First Production Sync
- Removes the duplicate full Production importer from normal Productions navigation and the Dash tools hub.
- Routes full Production synchronization to Programming Season Discovery & Sync.
- Retains the legacy importer for one transition release as an explicit administrator-only recovery route from unaligned Production records.
- Requires explicit recovery mode in both the recovery interface and protected server-side import handler.
- Continues blocking legacy recovery for Seasons already managed through Programming.
- Keeps manual Productions, the Alignment Audit, and targeted team/participant recovery available.

## 3.5.0 — Protected Parallel Operation
- Identifies Programming Season Discovery & Sync as the primary workflow for aligned Productions.
- Renames the duplicate Productions importer as a legacy recovery tool.
- Blocks legacy imports for Dash Seasons already aligned and managed through Programming.
- Keeps legacy recovery available for unaligned Productions and Seasons.
- Routes aligned Production edit screens back to Programming for synchronization.
- Adds recent companion projection results, counts, participant totals, and warnings to the Alignment Audit.
- Keeps all existing projection protections and never deletes or archives missing source records.

## 3.4.0 — Unified Programming Projection
- Uses Programming's normalized protected Dash sync as the single source for Production companion updates.
- Adds a companion preview to Programming for Production-designated Seasons.
- Creates a draft companion Production when needed and safely links it reciprocally.
- Creates or updates only the Divisions and Groups corresponding to Levels and Programs included in the successful Programming sync.
- Preserves existing Production titles, content, publication status, visibility, group types, presentation fields, and manually customized registration URLs.
- Never deletes or archives Production records when source records disappear.
- Adds opt-in participant synchronization after Groups are projected.
- Stores projection results and warnings for diagnostics and exposes a completion hook.

## 3.3.0 — Protected Programming Relationship Migration
- Added explicit selection controls for reviewed Season/Production, Division/Level, and Group/Program relationships.
- Preselects exact, already-linked, and child-confirmed pairs while leaving probable title matches unselected for manual review.
- Regenerates and validates the read-only audit at submission time before accepting any selected pair.
- Enforces one-to-one relationships and refuses stale, conflicting, missing, local-only, or Programming-only selections.
- Writes relationship metadata only; posts, content, Dash identities, publication status, participants, and presentation fields remain unchanged.
- Stores a compact snapshot before every migration and provides a protected rollback for the latest migration.

## 3.2.2 — Child-Confirmed Alignment Audit
- Uses unique exact Group/Program Dash matches to confirm their Division/Level parent relationship.
- Labels unused historical or standalone Programming Levels as Programming only with no migration needed.
- Flags exact child matches that point to multiple or contradictory parents.
- Keeps repeated titles with different Dash IDs separate and never merges them by name.
- Remains completely read-only and performs no migration writes.

## 3.2.1 — Read-Only Programming Alignment Audit
- Added a read-only Productions ↔ Programming audit under Productions > Dash.
- Compares Productions, Divisions, and Groups with Programming Seasons, Levels, and Programs.
- Proposes exact Dash-ID matches and conservative title-within-parent matches.
- Flags duplicate identifiers, invalid or contradictory links, parent mismatches, missing counterparts, and ambiguous titles.
- Identifies Production-only local records that should be preserved without a Programming counterpart.
- Performs no relationship writes or content migration.

## 3.2.0 — Programming Alignment Compatibility
- Added an optional linked Programming Season relationship to each Production.
- Added Programming alignment status to the Productions admin list.
- Kept the existing Production Dash import and synchronization workflow unchanged during Stage 1.

## 3.1.24 — Logo-Left Production Heroes
- Removed the homepage hero's outer border, top accent edge, and logo/information divider.
- Preserved one continuous translucent card with its existing rounded corners and shadow.
- Enlarged the Production logo in a dedicated left column on both the homepage and direct Production showcase.
- Preserved the direct Production page's existing written-information, status, and action hierarchy.
- Centered each Production logo at full available width on phones while preserving its natural proportions.

## 3.1.23 — Configurable Countdown Text Color
- Added a per-Production Countdown Text Color picker to complete Production editing and Guided Setup.
- Defaulted countdown numbers and labels to white while retaining the Production Secondary Color tile background.
- Applied the selected text color to both direct Production showcases and the main homepage.
- Kept the Homepage Builder's independent countdown colors available when Production color inheritance is disabled.

## 3.1.22 — Production Page Countdown Colors
- Replaced white countdown tiles only within direct Production showcases.
- Applied the Production Secondary Color to tile backgrounds and Accent Color to their numbers and labels.
- Added a final showcase-specific rule with sufficient priority to prevent generic countdown styles from overriding the Production colors.
- Preserved the complete version 3.1.21 homepage presentation without modification.

## 3.1.21 — Unified Homepage Showcase
- Combined the homepage hero logo and information columns inside one translucent card.
- Replaced the two independent borders and backgrounds with one outer branded border, accent line, overlay, and radius.
- Applied the Production Secondary Color to countdown tile backgrounds by default instead of leaving the legacy white tiles.
- Added a Homepage Builder toggle that restores the separate countdown background, number, and label controls when disabled.
- Preserved the responsive stacked presentation with a subtle internal divider.

## 3.1.20 — Branded Homepage Showcase & Venue
- Rebuilt the homepage hero as a responsive logo-and-information split layout.
- Restored the written Production title alongside the configured Show Logo.
- Applied Production Accent, Secondary, Overlay Color, and Information Box Opacity settings to the homepage showcase panels and actions.
- Added Venue / Plan Your Visit as an enabled, reorderable Homepage Builder section.
- Reused the current Production's venue name, address, parking notes, and full uncropped venue image on the homepage.
- Added stacked mobile layouts for both the homepage hero and venue section.

## 3.1.19 — Full Venue Artwork Layout
- Rebuilt venue details as a two-column information-and-artwork card on desktop.
- Loaded the full venue attachment and preserved its natural aspect ratio with no cover crop.
- Kept the venue card responsive by stacking copy before artwork on smaller screens.

## 3.1.18 — Relink Button Submission Fix
- Fixed the Relink Dash Season button being absorbed by WordPress's surrounding Production-edit form.
- Moved the protected relink submission into a valid external footer form while leaving its visible controls in the Dash Production sidebar.
- Retained server-side nonce, capability, Dash verification, and duplicate-season safeguards.

## 3.1.17 — Safe Dash Season Relinking
- Added a Relink Dash Season form to the Production editor's Dash sidebar.
- Suggested a likely season from Dash IDs retained by the Production's existing Divisions and Groups.
- Verified the selected season through the shared Dash Connector before restoring the link.
- Prevented duplicate Production-to-season relationships and reported the conflicting Production when found.
- Preserved local Production content and presentation while refreshing the Dash payload and generated registration destination.
- Added an intentionally tucked-away Change Linked Season form for correcting an existing relationship.
- Added the Production Volunteer action after Registration in homepage and public Production hero buttons.
- Made Participant Hub volunteer shortcuts prefer the Production-specific link before the shared Site Link fallback.

## 3.1.16 — Registration Accordion & Venue Images
- Added a compact Production → Division → Group registration accordion modeled on the Programming catalog.
- Displayed the accordion automatically on public Production pages only while their registration window is open.
- Restored the normal non-registration Performance Groups presentation outside the open window.
- Added `[ifp_registration production_id="123"]` for standalone registration pages with automatic opens-on and closed status messages.
- Added a copy-ready Production-specific registration shortcode to the Public Page editor tab.
- Added Registration Opens and Registration Closes controls to Guided Setup.
- Added a Media Library Venue Image selector and public venue-card artwork.
- Made hero registration actions respect the saved opening and closing dates.

## 3.1.15 — Upcoming Production Logos
- Added the configured Show Logo as an overlay on upcoming Production cards on the main homepage.
- Used a transparent, shadowed logo treatment without introducing a separate logo panel.
- Kept archive cards and existing card copy unchanged.

## 3.1.14 — Independent Showcase Overlays
- Added an Overlay Color independent from the Production Accent and Secondary colors.
- Added separate 0–100% opacity controls for the hero image overlay and information-box backgrounds.
- Allowed a completely unfiltered hero image with independently translucent countdown and performance-detail boxes.
- Preserved the former Secondary Color, 92% hero, and 78% panel treatment as fallbacks for existing Productions.
- Added the controls to both complete Production editing and Guided Setup.

## 3.1.13 — Production Showcase Branding & Layout
- Replaced fixed orange Production showcase eyebrows and labels with the Production Accent Color.
- Replaced the fixed navy hero wash and blue-gray information-card overlays with translucent versions of the Production Secondary Color.
- Kept hero typography white and retained existing branded action-button colors.
- Moved the Production description directly beneath the date range in the showcase hero.
- Removed the redundant About the Show card and its unused visibility option.
- Added the performance date range and Production description to the main homepage hero.
- Removed the separate Production Story homepage section, its renderer, and its builder controls while safely filtering the retired section from existing saved layouts.

## 3.1.12 — Scheduled Ticket Availability
- Added a Tickets Available On date to complete Production editing and Guided Setup.
- Disabled public ticket actions before the configured local availability date and displayed that date in place of an active link.
- Activated ticket links automatically when the availability date arrives.
- Removed ticket actions after the Production Closing Date & Time without deleting the saved URL.
- Applied the same ticket window to direct Production showcases, homepage buttons and audience pathways, and the Current Production shortcode.

## 3.1.11 — Production Logo Polish
- Removed the oversized translucent panel behind the Show Logo in the Production showcase About artwork.
- Kept the logo directly over the featured image with a subtle shadow for legibility.

## 3.1.10 — Multi-Day Important Dates
- Added an optional End Date field to Important Dates.
- Displayed multi-day date ranges on the homepage, Participant Hub, Production showcase, and Group performance assignments.
- Kept one-day entries unchanged when End Date is blank or matches Start Date.
- Kept ongoing multi-day entries in Participant Hub date queries until their final date.
- Added browser and server-side protection against an End Date earlier than Start Date.
- Preserved start/end time ranges alongside the new date ranges.
- Added the configured Show Logo over the featured artwork in the public Production showcase's About section.
- Renamed the Production showcase's mixed event section from Performance Dates to Important Dates.
- Made Upcoming Production countdowns progress through Registration Opens, Registration Closes, and then Opening Night according to the next future milestone.

## 3.1.9 — Lifecycle-Aware Production Pages
- Limited direct Production Participant/Skater Hubs to lifecycle Current Productions.
- Restored a dedicated public show presentation for Upcoming, Completed, and Archived Productions.
- Added status-aware hero labels, artwork, story, dates, public groups, venue, media, and sponsors.
- Kept registration and ticket actions on Upcoming showcases while suppressing them on past-production pages.
- Used only Production-specific registration and ticket URLs on showcases so a future show never inherits the Current show's global destination.
- Kept digital programs and trailers available on past-production pages when configured.
- Added a `show_registration` option to the Groups shortcode and disabled stale Group signup actions on past Productions.
- Made the direct URL switch presentations automatically whenever the Production lifecycle changes.

## 3.1.8 — Scheduled Production Lifecycle
- Added a Become Current On date to Guided Setup and the full Production editor.
- Promotes published Upcoming Productions to Current automatically when their scheduled local date arrives.
- Moves Current Productions to Completed automatically after their Closing Date & Time, or at the end of the Closing Date when no time is stored.
- Processes multiple overdue promotions chronologically so only the newest eligible Production remains Current.
- Uses both an hourly WordPress Cron event and a throttled request-time fallback for sites where Cron runs late.
- Preserves unpublished Upcoming Productions rather than making a private or draft record the public Current Production.
- Changed scheduled and manual Current handoffs so a displaced Current Production becomes Completed and appears in Memory Lane.

## 3.1.7 — Guided Setup for Existing Productions
- Fixed “Sorry, you are not allowed to access this page” on Guided Setup and other nested detail screens.
- Kept detailed submenu registrations intact for WordPress permission routing while hiding duplicate native rows visually through the grouped navigation.
- Added a Guided Setup selector for creating a new Production or completing an existing Draft, Upcoming, or Current Production.
- Prefilled existing titles, descriptions, season/year, performance dates, colors, links, publication state, and matching starter Important Dates.
- Updated the selected Production in place and preserved its lifecycle status unless an authorized user explicitly makes it Current.
- Reused matching starter Important Dates instead of creating duplicates.
- Added a Guided Setup row action to editable non-completed Productions.

## 3.1.6 — Dash Registration Links
- Added automatic DaySmart registration URLs for imported Production Seasons, Divisions/Leagues, and Groups/Teams.
- Reused the public Program, Program Level, and Team routes established in Ice & Field Programming.
- Used explicit registration URLs returned by Dash when available and generated the corresponding public route otherwise.
- Added editable Registration URL fields for Divisions and Groups and clarified the existing Production field.
- Preserved manually replaced registration URLs during future Dash imports while continuing to refresh Dash-managed URLs.
- Added Open Registration shortcuts to Dash-linked edit screens, a Production Registration quick link in the Participant Hub, and Register actions on public Group cards.
- Suppressed generated Team registration links when Dash explicitly reports that online signup is disabled.

## 3.1.5 — Production Toolbar Shortcut
- Added an Edit Production shortcut to the front-end WordPress admin toolbar on direct Production pages.
- Resolves the exact Production being viewed and checks its edit permission before displaying the link.

## 3.1.4 — Production Permalink Repair
- Confirmed that published Production records and their Production-scoped Participant Hubs resolve through WordPress's direct query format.
- Added an explicit top-priority `/productions/{slug}/` rewrite rule.
- Added a request-level Production resolver so friendly Production URLs still work when the stored rewrite table is stale or another component filters out the normal custom-post-type rule.
- Standardized the Production archive and permalink base to `productions` without inheriting the site's regular-post permalink front.

## 3.1.3 — Production Participant Hubs
- Replaced the older standalone public Production template with the Production-scoped Participant Hub.
- Added `production_id` support to the Participant Hub and countdown shortcodes.
- Scoped Participant Hub notices, dates, resources, contacts, artwork, and status information to the Production being viewed.
- Added a one-time rewrite-rule refresh so existing `/productions/{production-name}/` permalinks resolve after upgrade.
- Preserved WordPress publication and lifecycle-draft privacy checks; unpublished Productions remain unavailable to public visitors.

## 3.1.2 — Navigation Refinements
- Moved removal of the separate Productions custom-post-type menu to the final admin-menu cleanup pass.
- Prevents a second top-level Productions entry even when another component registers it later in the menu-building sequence.
- Preserves the grouped Productions menu and direct Production list and editor links.
- Added accessible, collapsible third-level shortcuts beneath the grouped menu sections.
- Automatically expands the section for the detailed screen currently being used and provides flyout shortcuts when the WordPress sidebar is collapsed.

## 3.1.1 — Grouped Admin Navigation
- Shortened the Productions submenu to eight durable sections: Dashboard, Productions, People & Groups, Communication, Participant Content, Website, Dash, and Settings.
- Added visual tool hubs under the new section headers so detailed screens remain easy to find without crowding the primary menu.
- Preserved existing direct URLs, bookmarks, edit links, and permissions for every moved screen.
- Kept the correct main section highlighted while using detailed Production, People, content, Website, Communication, and Dash screens.

## 3.1.0 — Communication Center
- Added a Communication Center for emailing an entire Production, Division, Group, or selected People.
- Added a simple visual WordPress editor and made every message HTML by default.
- Added a protected final-review step showing the resolved names and unique email addresses before sending.
- Sends one private copy per unique address, deduplicates People who belong to several Groups, and skips missing addresses with a visible warning.
- Includes manual Group roster email addresses even when a roster row is not linked to a Person record.
- Added reusable HTML Email Templates, including an option to save a sent message as a new template.
- Added local per-message history with subject, HTML body, audience, sender, recipient results, and mail-service handoff counts.
- Added Communication History to each Person record and direct composer shortcuts from Productions, Divisions, Groups, and filtered People lists.
- Added one-time capability repair for Administrators and Editors upgrading from older versions.
- Kept attachments and provider-level delivery, bounce, and open tracking for later 3.1 milestones.

## 3.0.2 — Stabilization and Privacy
- Centralized lifecycle status and legacy current-flag synchronization.
- Added an automatic migration for productions created before lifecycle metadata existed.
- Fixed the Production Wizard so the former Current production moves to Archived.
- Hid lifecycle Draft productions from public archives and direct public access.
- Preserved existing WordPress publication status and Group visibility during Dash re-imports.
- Fixed Current-only content leaking onto unrelated archived production pages.
- Protected private plugin content from anonymous WordPress REST API access.
- Added dedicated People capabilities for Administrators and Editors.
- Hardened People CSV exports against spreadsheet formulas.
- Added the missing Bulk Edit production-assignment hook.
- Added the missing Upcoming Productions label, heading, and limit controls to Homepage Builder.
- Allowed Editors who can manage production content to save Homepage Builder and Participant Hub settings.
- Clarified partial Person sync results when Dash customer notes return 403 or another permission error.
- Removed PHP 8-only string helpers and declared PHP 7.4 as the minimum version.
- Updated package documentation for the current release and the v3.1 Communication Center plan.

## 3.0.1
- Added production lifecycle statuses: Draft, Upcoming, Current, Completed, and Archived.
- Current production leads the homepage; Upcoming productions follow; Completed and Archived productions appear in Memory Lane.
- Added status badges and filtering to the Productions list.
- Added registration dates and production quick-link metadata.
- Dash Season imports default new productions to Upcoming while preserving existing lifecycle status.
- Added compatibility synchronization with the legacy current-production flag.


## 3.0.0 — Production Imports
- Import a complete Dash Season as a Production.
- Import Dash Leagues as Divisions and Dash Teams as Groups.
- Optionally synchronize all registered People during import.
- Duplicate-safe updates using Dash Season, League, Team, and Customer IDs.
- Added Divisions admin section and Division assignment on Groups.
- Added Dash Production summary card with Division, Group, and unique People counts.
- Existing team discovery remains available for targeted/manual imports.

## 2.9.2
- Added **Sync Now** for individual Dash-linked people.
- Added **View Raw Data** with side-by-side stored and live customer/customer-note payloads.
- Added emergency contact and emergency phone fields synchronized from Dash.
- Added read-only medical/customer notes synchronized from the Dash `customerNotes` relationship.
- Improved mapping for DaySmart phone and birthdate fields.

## 2.9.1 — Dash Customer Quick Links
- Made the Customer ID in each Person's Dash Connection box link directly to that customer's DaySmart Recreation profile.
- Added a new-tab external-link indicator, tooltip, and secure link attributes.
- Preserved the existing green Dash linked status badge and last-synchronized details.

## 2.9.0 — People
- Renamed the synchronized Participants section to People throughout WordPress admin.
- Added editable Person profiles with contact details, birthdate, internal notes, Dash ID, and last-sync details.
- Added visible Production and Group relationships to each Person profile.
- Expanded the People list with email, phone, productions, groups, Dash status, and last sync columns.
- Added Production and Group filters to the People list.
- Added CSV export for all people or the currently filtered set.
- Added BCC email shortcuts for filtered People and individual Groups using the administrator's default mail app.
- Added View People and Email Group controls to Group records.
- Preserved Dash-ID matching, shared Person records, roster synchronization, manual notes, and all v2.8 functionality.
