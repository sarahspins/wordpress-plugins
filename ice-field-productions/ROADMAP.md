# Ice & Field Productions Roadmap

## 3.1 — Communication Center

### 3.1.24 — Logo-Left Production Heroes (Shipped)

- Remove the outer border, accent edge, and internal divider from the homepage showcase.
- Preserve one continuous translucent surface with rounded corners and shadow.
- Enlarge and position the Production logo on the left of both the homepage and direct Production hero.
- Preserve the direct Production page's existing written content, status, and action hierarchy.
- Center and expand each Production logo to its available mobile width.

### 3.1.23 — Configurable Countdown Text Color (Shipped)

- Add a per-Production countdown text color with a white default.
- Use that color for numbers and labels on both direct Production pages and the homepage.
- Retain the Production Secondary Color as the tile background and preserve the Homepage Builder opt-out.

### 3.1.22 — Production Page Countdown Colors (Shipped)

- Remove white countdown tiles from direct Production showcase pages only.
- Use the Production Secondary Color for tile backgrounds and Accent Color for countdown text.
- Preserve the version 3.1.21 homepage presentation unchanged.

### 3.1.21 — Unified Homepage Showcase (Shipped)

- Contain the homepage logo and production information inside one branded card.
- Use one shared overlay and outer accent treatment with only a subtle divider between columns.
- Make countdown tiles inherit the Production Secondary Color by default while retaining an optional custom-color mode.

### 3.1.20 — Branded Homepage Showcase & Venue (Shipped)

- Present the current Production homepage hero as coordinated logo and information panels.
- Restore the written Production title while retaining the Show Logo.
- Reuse Production branding colors and information-box opacity in the homepage showcase.
- Add the current Production's venue details and full artwork as a reorderable homepage section.
- Stack both layouts cleanly on mobile.

### 3.1.19 — Full Venue Artwork Layout (Shipped)

- Present venue information on the left and full, uncropped venue artwork on the right.
- Stack the layout cleanly on mobile without changing the image's natural proportions.

### 3.1.18 — Relink Button Submission Fix (Shipped)

- Submit the Production editor's relinking controls through a valid form outside WordPress's surrounding post editor form.
- Preserve the existing sidebar placement and all verification safeguards.

### 3.1.17 — Safe Dash Season Relinking (Shipped)

- Relink an existing WordPress Production to a verified Dash Season from its editor.
- Suggest the likely season from child Division and Group records when the Production-level connection disappears.
- Block duplicate season ownership and preserve local Production presentation.
- Allow an existing link to be corrected deliberately without reimporting or creating a duplicate.
- Surface the saved Production Volunteer link after Registration in public hero actions.

### 3.1.16 — Registration Accordion & Venue Images (Shipped)

- Present open Production registration as a compact Division and Group accordion.
- Reuse Dash-synchronized registration links at the Production, Division, and Group levels.
- Provide a standalone, lifecycle-aware Production registration shortcode.
- Expose the registration window in Guided Setup.
- Return to the non-registration Performance Groups display when registration is not open.
- Add selectable venue artwork to Production venue details.

### 3.1.15 — Upcoming Production Logos (Shipped)

- Overlay each upcoming Production's Show Logo on its homepage featured artwork.
- Preserve the existing card layout and keep past-production cards unchanged.

### 3.1.14 — Independent Showcase Overlays (Shipped)

- Add an Overlay Color independent from Accent and Secondary branding colors.
- Control hero-image darkness and information-box transparency separately.
- Allow a zero-opacity hero wash while preserving separately tinted translucent boxes.
- Expose the controls in both the complete editor and Guided Setup without changing existing Production presentation defaults.

### 3.1.13 — Production Showcase Branding & Layout (Shipped)

- Apply each Production's Accent Color to showcase eyebrows, labels, and action accents.
- Tint the showcase hero wash and translucent information panels with the Production Secondary Color.
- Retain high-contrast hero typography and existing branded buttons.
- Place the Production description beneath the hero date range and remove the redundant About the Show card.
- Mirror the consolidated hero on the main homepage and retire the duplicate Production Story section and controls.

### 3.1.12 — Scheduled Ticket Availability (Shipped)

- Add a Production-level Tickets Available On date to complete editing and Guided Setup.
- Keep ticket actions inactive until that date and activate them automatically in the site's local timezone.
- Remove public ticket actions after the final Closing Date & Time without erasing the saved destination.
- Apply one ticket-sales window across the Production showcase, homepage, pathways, and Current Production shortcode.

### 3.1.11 — Production Logo Polish (Shipped)

- Remove the heavy backdrop behind the About-artwork Show Logo.
- Preserve logo legibility with a lightweight shadow rather than a separate panel.

### 3.1.10 — Multi-Day Important Dates (Shipped)

- Add an optional End Date while leaving existing one-day Important Dates unchanged.
- Display date ranges consistently anywhere Important Dates appear.
- Keep ongoing multi-day dates visible through their final day and retain existing time ranges.
- Display the Show Logo with the featured artwork in the public Production showcase's About section.
- Use the inclusive Important Dates heading for mixed registration, rehearsal, deadline, and performance entries.
- Prioritize future registration opening and closing milestones before Opening Night in Upcoming Production countdowns.

### 3.1.9 — Lifecycle-Aware Production Pages (Shipped)

- Use the Participant/Skater Hub presentation only for the Current Production.
- Present Upcoming Productions as show previews and Completed/Archived Productions as show memories.
- Remove registration and ticket actions from past productions while preserving public programs, trailers, cast, and sponsor history.

### 3.1.8 — Scheduled Production Lifecycle (Shipped)

- Allow an Upcoming Production to become Current on a chosen date.
- Move a Current Production to Completed after its final Closing Date & Time.
- Combine an hourly scheduled task with a safe request-time fallback and keep unpublished Productions from becoming public automatically.

### 3.1.7 — Existing Production Guided Setup (Shipped)

- Keep detailed screens registered for WordPress permission checks while maintaining the compact grouped menu visually.
- Allow Guided Setup to prefill and update an existing Draft, Upcoming, or Current Production.
- Preserve lifecycle state by default and reuse matching starter Important Dates.

### 3.1.6 — Dash Registration Links (Shipped)

- Generate and synchronize public DaySmart registration URLs for imported Production Seasons, Divisions/Leagues, and Groups/Teams.
- Preserve intentional WordPress URL overrides during later Dash syncs.
- Surface the links in the relevant edit screens and public Production/Group displays.

### 3.1.5 — Production Toolbar Shortcut (Shipped)

- Add a permission-aware Edit Production link to the front-end WordPress toolbar on direct Production Participant Hubs.

### 3.1.4 — Production Permalink Repair (Shipped)

- Add an explicit Production rewrite rule and a request-level fallback for installations where the normal custom-post-type rule is missing.
- Preserve public-status and lifecycle privacy checks after resolving friendly Production URLs.

### 3.1.3 — Production Participant Hubs (Shipped)

- Route each public Production permalink to the Participant Hub for that exact Production.
- Allow Participant Hub content queries and countdowns to use an explicit Production rather than only the global Current Production.
- Refresh Production rewrite rules once after upgrading.

### 3.1.2 — Navigation Refinements (Shipped)

- Remove the legacy standalone Productions post-type menu after the complete WordPress admin menu has been assembled.
- Keep the grouped Productions navigation as the single top-level entry.
- Add expandable direct shortcuts beneath the grouped section headers so the visual hub pages are helpful but never required.

### 3.1.1 — Grouped Admin Navigation (Shipped)

- Consolidate the growing Productions submenu into a small set of durable sections.
- Add visual landing pages for Production, People, participant-content, Website, and Dash tools.
- Preserve direct links and section highlighting for the detailed screens moved beneath those hubs.

### 3.1.0 — Communication Foundation (Shipped)

- Email an entire Production, Division, Group, or selected People.
- Compose HTML email by default in a simple visual WordPress editor.
- Review and deduplicate the complete recipient list before sending.
- Send a private copy to each unique address.
- Create and reuse Email Templates.
- Keep local per-message and per-Person communication history.
- Record WordPress mail-service handoff successes and failures without claiming final delivery.

### Later 3.1 Milestones

- Add controlled file attachments with size and file-type guardrails.
- Add sender-name, reply-to, and reusable email-appearance controls if needed.
- Integrate delivery, bounce, and open tracking only through a compatible mail provider that exposes trustworthy event data.
- Add retry tools for failed handoffs without duplicating messages that were already accepted.

## 3.2 — Controlled Two-Way Dash Updates (Planned)

- Keep the normal Connector configuration read-only; enable outbound updates only after installing a separately approved key with the narrowest practical permissions.
- Start with `Program → Update` for supported Production/Season, Division/League, and Group/Team titles and descriptions. Evaluate `Schedule → Update` separately before allowing any date or time changes.
- Do not request Create, Delete, Financial, POS, or Registration permissions for this feature.
- Compare the current WordPress value, the last value received from Dash, and the current live Dash value before proposing an update.
- Show outbound changes in a preview with per-field and select/deselect-all controls; nothing is sent to Dash merely because a WordPress post was saved.
- Send one resource update at a time, matching Dash's single-object PATCH behavior, and stop cleanly on authorization, validation, or conflict errors.
- Keep an audit log containing the administrator, timestamp, resource type and ID, changed fields, result, and a non-sensitive response summary.
- Add conflict protection so a newer Dash edit is never silently overwritten by an older WordPress value.
- Test the complete workflow against a Dash sandbox before enabling it on the live account.

## Later Releases

- Expand communication tools only after the HTML composer, recipient resolution, privacy behavior, and local history have proven reliable in normal Production use.
- Continue keeping Dash authentication and remote-data access in the shared Connector.
- Preserve People and relationship matching by stable Dash IDs rather than names.
