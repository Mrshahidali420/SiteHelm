# Changelog

All notable changes to SiteHelm are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and SiteHelm
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Every entry names the user-visible outcome. Internal refactors, test additions, and
documentation-only changes are not listed unless they change what an agent can do or how
an operation behaves.

## [Unreleased]

### Changed
- **A refusal now says which of three problems it is.** "This is not available" used to cover a
  plugin the site has not installed, a feature the site has no Pro licence for, and a service
  outside the site that did not answer. Those need three different responses — install
  something, buy something, or just try again — and only the last one fixes itself. There are
  now three codes instead of one: `integration_unavailable` still means a dependency this site
  does not have active, `integration_unlicensed` means the operation is part of Pro, and
  `upstream_unavailable` means something outside the site was slow or down and the same call is
  worth repeating. Nothing that already worked changed its answer.

### Fixed
- **Content types that are not public could not be listed.** `content-list` asked whether a
  content type was visible to visitors, so an enquiry log, a testimonial store, or any of the
  custom types a plugin registers for the admin screens only were refused as though they did
  not exist. It now asks whether the type has an editing screen and whether the account may
  edit it, which is the question that was meant all along. Reading one of those items already
  worked, so listing and reading now work together.
- **A wrong property name was refused without saying what the right one is.** Sending
  `perPage` instead of `count`, or any other near-miss, produced a refusal that named the
  property that was wrong and left the caller to guess again. Every operation now lists the
  property names it accepts.

### Added
- **Menus can be created.** Every menu operation needed a menu to already exist, so a site
  built from nothing had to have its first menu made by hand in WordPress before anything
  else about menus could be done. `menu-create` takes a name and makes one empty menu; add
  items to it with `menu-item-create` and show it with `menu-location-assign`. A name the
  site already uses is refused, the name is reported the way WordPress will actually store
  it, and undoing the change deletes the menu it made and nothing else.
- **The site icon and the site logo can be set.** Two of the first things any new site needs
  — the icon in the browser tab and the logo in the header — had to be set by hand in
  WordPress after everything else was built. `site-settings-set` now takes `siteIcon` and
  `siteLogo`, reading the settings reports both, and 0 removes either one. Naming an id that
  is not an image is refused, an icon smaller than the 512 pixels WordPress needs is refused
  with its actual size, and a logo is refused on a theme that does not show one rather than
  being written where nothing would read it.
- **`content-list` accepts `private` and `any` as a status.** `private` lists items published
  for logged-in readers only, and `any` lists everything except items in the trash, spelled
  the way WordPress spells it.
- **Content can be put in a chosen order.** Pages, and any content type whose order is kept by
  hand rather than by date, have a position WordPress calls the menu order; nothing in SiteHelm
  could set it, so an agent could build a set of pages and then had to ask someone to open
  WordPress and drag them into sequence. `content-create` and `content-update` now take
  `menuOrder`, reading it back reports it, and listing content carries it for every item, so
  the order that was asked for can be checked without opening each page. A rollback puts the
  old position back with everything else it restores.
- **Snippets can be stored in WPCode or Code Snippets, in Pro.** If a site already runs one
  of those plugins, that is where its code belongs, and SiteHelm now writes into it: name
  `wpcode` or `code-snippets` as the host on `code-snippet-write`, `code-css-write` or
  `code-js-write` and the snippet becomes a real snippet in that plugin's library, tagged so
  the owner can see who put it there and editable in the screen they already use. It is
  stored switched off and SiteHelm will not switch it on. Everything that makes SiteHelm's
  own snippets survivable — the time limit that switches code back off, the site check, safe
  mode, the quarantine after a fatal error — belongs to SiteHelm's loader and cannot reach
  code another plugin runs, so the preview says so plainly and the activation operation
  refuses by name. One key is still one snippet: a write naming a library for a key another
  library already holds is refused rather than quietly duplicated.
- **Plugins and themes can now be deleted, in Pro.** SiteHelm could install, switch on, switch
  off, update and swap; it could not remove anything, so a site tidied up by an agent kept
  every plugin it had ever tried. `plugin-delete` and `theme-delete` remove the files for
  good. A plugin that is switched on is refused, so is one the network activated, and so is
  SiteHelm itself; a theme is refused while it is the live one or while another installed
  theme is built on it. Both are previewed before anything happens, and the preview says
  plainly that there is no way back and that the plugin or theme's own database rows stay
  behind. Only a client connected with full permission can call them.

### Changed
- **Every tool now says what it is for, and says its list is complete.** An agent that
  connected some time ago was working from the tool list it saw then, and had no way to tell
  whether newer operations existed. The eleven tools never change; only the operations behind
  them do, and those are read fresh on every call. Each tool's description now names its
  subjects and invites a call with no operation to list what this site publishes on it.

### Fixed
- **Writing a custom field was refused on every site.** `content-meta-update` writes only the
  fields a site has named, and there was nowhere to name them: the list it read was an option
  nothing ever wrote, so the operation listed itself as available and then refused every
  request it was given. The Status screen now has a box for those field names, one per line,
  and a theme or plugin can add its own through the `sitehelm_meta_allowlist` filter. Both go
  through the same rules, so a name SiteHelm would never write — anything starting with an
  underscore, anything with a space in it — is refused as it is typed rather than sitting in
  the list looking like it works.
- **A number or a yes/no value was refused as a custom field.** Fields holding a price, a
  count or a switch had to be sent as text or they were turned away. They can now be sent as
  what they are; WordPress stores custom fields as text, so the preview says exactly what will
  be stored — `1.0` becomes `1`, and true and false become `1` and `0`.
- **"View details" answered "Plugin not found".** Both routes into the plugin details panel
  — the link beside the version on the Plugins screen, and "View version X details" in the
  update row — asked the WordPress.org directory about a plugin that has never been in it.
  SiteHelm now answers that request itself, showing the release notes for the version on
  offer, and still opens with what is installed when GitHub cannot be reached.
- **"By SiteHelm" now links to the website** on the Plugins screen and in the details panel.
- **The activity log stopped naming the app that made the change.** An app names itself once,
  when it opens the connection, and then works for as long as it stays open — a whole day,
  often with quiet hours in it. SiteHelm remembered that name for an hour, so everything done
  after the first hour was filed against nobody and read as "An unnamed app changed a plugin".
  The name is now kept with the account that connected and lasts until a different app
  connects as that account.
- **A plugin or theme in the log is named.** Rows recorded the entry file or the
  WordPress.org slug and read as "changed a plugin"; they now say "changed the Elementor
  plugin", falling back to the kind when the plugin or theme has since been deleted.
- **The account page is no longer in the menu.** SiteHelm is installed on sites its buyer
  does not own, and the licensing account page prints the licence holder's name, email
  address, billing address, payment history and API keys into the admin menu of every site
  the licence covers. It still answers at its own address, which is what the licence screen
  links to, so syncing or moving a licence is unchanged.

## [0.12.0] — 2026-09-04

**Update this one promptly if you have bought Pro.** Activating a licence led to a page that
refused to open, so a paid site could not turn the add-on on by following either route the
plugin offered. That is fixed below, and it is the reason this release should not wait.

### Fixed — the licence route
- **"Activate a licence" led to a page that refused to open.** The button on Health, and the
  sentence an agent was given when a Pro operation refused, both pointed at an account page
  the licensing SDK never registers for an add-on, so following either was answered with
  "Sorry, you are not allowed to access this page" — and the Add-Ons screen, the other route
  offered, could not load at all on a host that cannot reach the licensing service. Every
  link about Pro now goes to SiteHelm's own Upgrade screen, which this plugin registers and
  which works whether or not anything outbound succeeds, and the Add-Ons screen is gone.

### Added
- **An agent can now see the page a visitor is served, not the row it wrote.** Every write
  SiteHelm performs reports success by reading the database back, which says the value was
  stored and says nothing about whether the page renders. `content-rendered-get` fetches one
  published item's own front-end address and reports what came back: the status code, any
  redirect, the title, meta description, canonical and robots tags, the Open Graph and
  Twitter tags, the heading outline with a count of H1s, how many images carry no alt text,
  how many links point inside the site and how many out, and the word count — with the
  markup itself on request. The address is not something you pass in: there is no property
  for one, so the only page that can ever be fetched is the permalink of the item you named,
  on this site's own host. Drafts, password-protected items and post types with no public
  page are refused before anything is requested, because a fetch that carries no cookies
  would report them as broken.

- **A section can now be asked for full bleed, instead of being told what padding to
  cancel.** Elementor's kit puts 10px on all four sides of every container and boxes its
  content by default, and neither of those is stored on the container itself — so a section
  built to run edge to edge was written, read back exactly as sent, verified green, and
  rendered inset, with nothing in the response to say why. `elementor-element-add` takes a
  `preset` of `full-bleed` on a container, which stores the zeroed padding and the full-width
  content the look actually needs. It is shorthand, never an override: sending your own
  padding or content width beside it is refused rather than silently losing one of them.

- **A free site now says what Pro would add, instead of reading as impossible.** Ask an
  agent to do something only the add-on does and it used to answer that SiteHelm cannot do
  it at all — because a listing is the only place most agents look, and an operation absent
  from one does not read as locked, it reads as unavailable anywhere. Every listing now ends
  with the operations that dispatcher would gain, each one named and described, and where to
  read about them. An operation the add-on registered and the site then switched off is not
  listed: it is not for sale, it is turned off.

- **Upgrading and activating now happen inside wp-admin, on a screen SiteHelm owns.**
  "Upgrade to Pro" opens a page in the console that shows every plan with its price —
  annual and lifetime, the recommended one flagged — and takes you to the same Freemius
  checkout the website does. Prices come from wpsitehelm.com and are cached for half a day,
  with the plugin's own copy standing in whenever the site cannot be reached, so the page
  never shows a price it is not sure of. A site that already has the add-on installed sees
  the licence field first instead of the plans, and a site with an active licence is not
  sold to at all.
- **A site running Pro without a licence says so on every admin screen**, not only on the
  ones you happen to open in SiteHelm, and the notice leads to the field where the key
  goes. The key itself is entered in Freemius's own dialog: SiteHelm never reads, stores or
  forwards it.

### Changed
- **Getting started asks for one thing, in a dialog, instead of handing you a list of five.**
  Home used to open with a numbered "Get started" block whose first step was connecting an
  app and whose other four — permissions, a test call, a first change, undoing it — read as
  four more things you had to finish before the plugin counted as set up. None of them were
  required. Connecting now gets a dialog of its own that opens on any SiteHelm screen while
  nothing can reach the site, says what an app needs and how long it takes, and goes to the
  Connect tab; "Not now" or the × closes it for that administrator for good, and a connected
  site never sees it again. The other four moved below the numbers as "When you're ready" —
  no numbering, no tally, no order — and the list removes itself once they are all done.
- **Connect leads with the credential, not the address.** The application-password path needs
  two things and the screen only ever showed one, with the working path — create a password,
  then copy a snippet that already carries it — sitting below the fold. Choosing that path
  now shows a card that says so in a sentence and a button straight to the credential form,
  which sits above the snippets that carry it, with the bare address demoted to the bottom
  for a client the snippets do not cover. The sign-in card no longer claims the address is
  the whole configuration without qualification: it says which apps that is true for, and
  what to do when nothing happens.

### Fixed
- **Editing the words in an Elementor heading no longer breaks the widget, or quietly
  deletes the links inside it.** Elementor's newer widgets do not store their text as a
  string: they store it beside the editor's own record of the links, bold and italic runs
  inside that text. A write that sent the words on their own put them where that record
  belongs, which the page rendered perfectly — and then the first person to open the widget
  and press update was told "Settings validation failed", with nothing to say when the
  damage was done. Writes now store the words in the shape the editor reads, and carry the
  existing formatting across an update that only changes the wording, so a link inside a
  sentence survives a rewrite of that sentence. Because formatting is anchored to positions
  in the text it was written against, a plan that keeps it now says so and suggests checking
  the widget when the wording changed substantially. Asking for the formatting to be cleared
  is still possible: send an empty set of it deliberately.
- **Reading a large Elementor page no longer returns a response the client cannot receive.**
  A real landing page nests containers inside containers and reaches several hundred elements
  without anybody thinking of it as large; returned whole, that tree was megabytes of JSON,
  and the app on the other end either truncated it, spent its whole context on it, or dropped
  it — three failures that all read as "the read did not work" and none of which said the size
  was why. `elementor-document-get` now answers a shorter true tree instead: it drops the
  deepest levels until the response fits, and reports in a new `narrowed` member how deep it
  went and how many elements it left out. An element whose children were dropped still says
  how many it has, so there is no way to mistake the shortened answer for the whole page. To
  see what was left out, name an element with the new `rootId` and get that band back at full
  depth. The totals and the authoring hints keep describing the whole document either way.
- **A template SiteHelm created no longer goes missing from the screen you would look at
  next.** Elementor records what a template is in two places: a value on the template itself,
  which is what this plugin's reads and its own verification ask for, and a term in the
  library's taxonomy, which is what the Templates list and the Theme Builder actually query
  by. Creating, saving and importing all wrote the first and not the second, so a header came
  back correct from every check and was absent from the only list an operator would open.
  All three now record both.
- **A global style class no longer stores properties Elementor will never render.** The
  repository these classes live in keeps whatever it is handed; the parsing that decides
  which style properties are real happens a layer above it, on the route the editor uses. A
  property outside that schema was therefore stored intact, read back identical, and applied
  to nothing. Creates and updates now ask Elementor what it accepts before anything is
  written, store its answer rather than the request, and name every discarded property in a
  warning and in the preview. A class Elementor keeps nothing of is refused, because it would
  render nothing at all. An Elementor that cannot be asked is a warning and not a refusal.
- **Buttons in the console were drawn in their own background colour, label and all.** A link
  colour meant for bare links in prose outweighed every button, tab and menu item built from
  an anchor, so a primary button rendered as a solid block of colour with the text
  invisible inside it. The rule now applies only to links that carry no class of
  their own.

## [0.11.0] — 2026-09-03

### Added
- **Home opens with five steps that tick themselves off.** Connect a client, choose what it
  may touch, make a test call, make a first change, undo it — each with a line of plain help
  and a button to the right tab. Nothing is remembered and nothing is dismissed: every step
  reads its own answer back off the site, and once all five are done the block shrinks to one
  line you can fold open again.
- **Clients can sign in to the site themselves, instead of being handed a password.** An app
  can now register with the site, send you to a page in your own dashboard that names it and
  asks whether to allow it, and hold its own credential afterwards — nothing to copy, nothing
  to paste, and a connection you can see and cut off from the site rather than from the app.
  It is on wherever the site is served over HTTPS and can be switched off entirely.
  Application passwords keep working exactly as they did; this is a second way in, not a
  replacement, and a connection made this way still acts as the account that approved it and
  can do nothing that account could not.
- **Connect now asks how your app signs in before it shows you anything to paste**, and
  answers for the connection afterwards. Pick signing in or an application password and the
  snippets follow the choice; on a site that cannot offer signing in, the screen says which
  of the two reasons it is and points at the path that does work. Below that: every app that
  has signed in, with when it registered, when it was last let in, how many live tokens it
  holds, and a **Sign out** or **Remove** button that names the app before it acts. Below
  that again: whether apps may sign in at all, the address they are given when they do — for
  hosts that answer on a different domain to the one WordPress has recorded — and a **Test
  discovery** button that asks this site for its own sign-in documents over the network and
  reports, per address, whether the answer came from this site or from something else sitting
  in front of it. The same verdict appears on Health, and the folded "Your app cannot sign
  in" note under the sign-in card explains the five failures every client reports identically
  as "could not connect".

### Fixed
- **A client that asks for an older MCP protocol revision now gets the one it asked for.**
  The handshake answered with this server's newest revision whatever the client named, and
  several clients read that disagreement as the end of the conversation and never asked for
  the tool list — so the whole site looked like it had no operations at all. The three
  revisions SiteHelm actually speaks are echoed back; anything else, or nothing, still gets
  the newest.
- **Two schema shapes that strict clients refused to load.** A menu item's `target` offered
  an empty string as one of its two choices, and the dispatcher tools never said which of
  their inputs were required. Validators that check a tool definition before calling it
  rejected both, and a client running one could not use the tool at all. "Same tab" is now
  the plain `_self` you would write in HTML, on the way in and on the way back out; an empty
  string is still accepted and still means the same thing, but it is deprecated and no
  longer listed.
- **Two sites no longer collide in one client's config.** Every generated snippet named the
  server `sitehelm`, so adding a second site to the same client overwrote the first. The
  name now carries the site's host — `sitehelm-example-com` — everywhere Connect shows it.
- **One unreadable global class no longer hides all the others.** `elementor-global-class-list`
  gave up on the whole set when a single stored entry was in a shape it could not resolve,
  leaving nobody able to see which entry was at fault. The bad entry is now reported in its
  place with a short reason, and the rest of the list answers as usual.
- **A style class nothing wears is refused instead of silently rendering nothing.** A layout
  could define a local style on an element whose settings never referenced it: Elementor
  stored the definition, the write reported success, and the page looked untouched. The
  refusal names the style class and what to add.

## [0.10.0] — 2026-09-01

### Added
- **SiteHelm now tells the agent driving it how to build a page.** Connecting returns a short
  set of instructions: how a write is previewed and then applied, and the four mistakes that
  produce a page which reports success and still looks wrong — leaving the layout at its
  default so the theme's header, footer and title stay put, leaving containers at Elementor's
  10px kit padding so nothing runs edge to edge, writing a hover colour that only works over a
  light background, and giving a section's CSS class names general enough to collide with the
  theme. They are deliberately terse, because they are sent on every connection.
- **`elementor-document-get` reports what a page has earned a note about.** Alongside its
  elements it now returns `hints`: whether the layout is still the default, whether the two
  layout records disagree, and how many top-level containers will inherit the kit's padding.
  The list is always there and is usually empty — it only ever says something the page itself
  demonstrates, never general advice.

- **Every Elementor write now says so when an image will be served without `srcset`.** A
  media setting given an image URL with no media-library attachment stores and renders, but
  WordPress cannot build `srcset` or `sizes` for it, cannot add the `wp-image` class, and will
  not lazy-load it — so the page serves one full-size file to every visitor. The plan now
  carries a warning naming the setting, and a bulk write that degrades many images reports the
  totals instead of a wall of sentences. It is a warning, not a refusal: the write still
  happens, because pointing a widget at an image outside the library is a legitimate thing to
  ask for.

### Changed
- **Bulk SEO metadata and audit fixes are free** — `content-seo-bulk-set` and
  `content-seo-audit-fix` shipped in SiteHelm Pro from 0.2.0 and now ship here. Batch size
  was never a good reason to charge: the free plugin already carries the single-post write
  each of them repeats, so an agent could always reproduce either in a loop — but only by
  giving up the one preview, one snapshot and one rollback the batched form performs. That
  put the safer path behind the licence and the riskier one in front of it. Both operations
  are unchanged in behaviour; they simply no longer ask for a licence.
- **An add-on that has not caught up keeps working.** The two plugins update on their own
  schedules, so a site can briefly run this version beside a SiteHelm Pro that still
  registers the same two operations. It now yields: an identifier the add-on already holds
  is left with the add-on, every other Pro operation still registers, and this plugin picks
  the identifier up on the next add-on update. Nothing is lost in between, and the same
  rule covers any future operation that moves out of the add-on.

### Fixed
- **A page whose elements were written without ids lost every style on it.** Elementor
  generates its CSS one element at a time, keyed on each element's own id, and an element
  that arrived without one produced a selector with nothing after the prefix. CSS discards
  a whole rule group when any selector in it is malformed, so a single unnamed element took
  the entire page's stylesheet with it — the page still held its content, its settings read
  back exactly as written, and it rendered unstyled. Every element now gets an id as it is
  written, and a page an earlier version left in that state repairs itself the next time
  anything on it is written.
- **Repeater rows are named, so a row can be styled on its own.** Elementor gives each row
  of a repeater control — an icon in a social-icons list, a tab, a slide, a form field —
  an id of its own, and generates that row's styles against it. The three operations that
  merge settings into an existing element wrote rows with no id at all, which cost the
  ability to style or address any single row, plus the row identity Elementor's editor
  tracks for the open tab, the current slide and the active accordion panel. The rows
  rendered, so nothing reported a problem. They are now named on the same terms as
  elements: deterministically, and a row that arrives with an id of its own keeps it.
- **A style setting Elementor would store but never render is now refused.** Elementor hides a
  control whose group switcher is unset, and drops its value at CSS-generation time: writing
  `background_color` without `background_background`, or `border_color` without
  `border_border`, was accepted, saved, read back unchanged, and rendered nowhere. Those
  writes are now refused before the save, naming the companion setting and the values it
  accepts, so the fix is one more key in the same request rather than a page that reports
  success and looks untouched. The check reads only what the widget itself declares, so it
  leaves alone the groups that genuinely need no switcher — typography and box shadow — and
  stands aside on anything it cannot read with certainty.
- **Setting an Elementor page layout actually changes the page now.** WordPress and
  Elementor each keep their own record of which template a page uses, and only WordPress's
  decides what a visitor sees. SiteHelm was writing Elementor's, so `canvas` or
  `headerFooter` was stored, read back correctly, and reported as verified while the page
  went on rendering with the theme's header, footer and title — the change looked done from
  every angle except the site itself. Both records are now written together, and rolling
  back restores both. Reads report the layout the page is really rendered with, and say so
  when the two records disagree; every page an earlier version set a layout on is in that
  state, and setting the layout again repairs it. Pages created with a layout are no longer
  born that way.
- **`elementor-document-get` now returns the page's own page settings** — the layout it
  renders with, whether the theme title is hidden, and the whole stored row behind both.
  Reading a page used to answer only its elements, so anything built from that read came out
  inside the wrong page frame, and there was no way to tell.
- **Elementor edits work on ordinary widgets again.** Elementor ships two kinds of widget:
  the newer atomic ones, and the classic ones nearly every page is actually built from —
  HTML, Heading, Image, Text Editor, Button, Shortcode, and everything third-party plugins
  add. SiteHelm could only read the newer kind's settings, so a write touching a classic
  widget was refused with a message blaming Elementor for not being loaded, and retrying
  never helped. Worse, because a save validates the whole page at once, a single classic
  widget anywhere on it blocked every edit to that page. SiteHelm now recognises both kinds,
  writes each in the form Elementor stores it, and still refuses a setting the widget does
  not accept — so an edit is never silently discarded.
- **Containers, sections and columns can be styled again.** Every Elementor settings write
  used to refuse anything that was not a widget, so padding, width, background and gap on a
  container — the settings that decide whether a section runs edge to edge — could not be
  changed by any operation at all. A page built entirely through SiteHelm therefore kept
  Elementor's default 10px on all four sides of every container and there was nothing an
  agent could do about it. Elementor keeps two registries of settings, one for widgets and
  one for layout elements, and SiteHelm only ever read the first; it now reads whichever one
  owns the element in hand. A container is still never checked against a widget's settings,
  which is what the old refusal was really protecting.
- **Adding a container with a setting it does not accept is refused.** The check already ran
  when a container was edited, but not when one was created, so a misspelled key was accepted,
  quietly dropped by Elementor, and reported as written. Both paths now check the same way.

## [0.9.0] — 2026-08-30

### Added
- **Plugins and themes, listed** (REQ-0085) — `system-plugin-list` and `system-theme-list`
  report every plugin and theme the site has installed: version, which are active, which
  the network activated, which theme is live, and which have an update waiting. The update
  column is read from WordPress's own last check and stamped with when that check happened,
  so it is exactly as fresh as the Plugins screen and never sends the site off to
  wordpress.org just because an agent asked a question.
- **Acting on them is SiteHelm Pro 0.7.0** — activate, deactivate, switch theme, update
  either, and install either. The three that flip an option are previewed, snapshotted and
  reversible; the two updates and the two installs say plainly that they cannot be rolled
  back, because WordPress has no clean way back to an older version and a rollback that
  quietly did nothing would be worse than one that refuses. Installing reaches
  WordPress.org and nowhere else: it takes a slug, there is no argument anywhere that
  accepts a web address, a zip or a file path, and what is installed is stored switched off
  — a theme is never made live. A site that sets `DISALLOW_FILE_MODS` or
  `DISALLOW_FILE_EDIT` refuses the four file writes by name and keeps the three option
  flips, because locking file modifications is not the same as declining to activate a
  plugin.

## [0.8.0] — 2026-08-30

### Added
- **Updates straight from GitHub** — the plugin now answers WordPress's own update check
  from its GitHub releases, so a new version appears on the Plugins screen and installs
  with one click like any other plugin. Only the built release zip is ever offered, the
  lookup is cached for twelve hours, and a failed lookup never slows an admin page down.
  The console also says plainly when the installed version is behind the latest release.
- **The console tells you when Pro would have helped** — calling a Pro operation without a
  licence now refuses with a message that names SiteHelm Pro and where to get it, instead
  of a bare "unavailable". The Home screen carries a Pro card, the Plugins row a Get Pro
  link, and the menu an Upgrade entry — all of which disappear once a licence is active.
- **Community** — a console link and help-menu entry to the SiteHelm community group, for
  questions that deserve a faster answer than an issue tracker gives.
- **WordPress.org listing files** — `readme.txt` in the directory format, with banners,
  icons and console screenshots under `assets-wporg/`, ahead of the directory submission.

## [0.7.0] — 2026-08-29

### Changed
- **A risk level for code, and the gate that goes with it** (REQ-0107) — operations that
  store or run code get their own tier above High, because the honest claim about them is
  different: for everything else we can say what will change, and for a program we can only
  say what was stored. Adding the tier turned up a real hole. The "Read & edit" permission
  level — the one whose own description promises an app can change things but never delete —
  decided what to allow by asking whether an operation was High, so anything above High was
  not High and went straight through. It now compares levels in order, which refuses a new
  top tier by default rather than admitting it. Nothing in the free plugin uses the new tier;
  the console learns the words for it, and the test suite refuses any free operation that
  claims either the tier or the code module. The Pro screen also lists the eighteen
  operations that module will carry, so the console can say what switching it on would
  let an app do before any of it is written.
- **A preview never reproduces an executable payload** (REQ-0106) — where a change
  touches a code field, the before-and-after shows a byte count and a short digest
  instead of the body. The values a preview renders travel further than the preview:
  into the response, into the stored plan an operator approves, and into the rollback
  table in the console. A snippet's contents are routinely an API key or an SMTP
  password, so those three places would have held a live credential. The change is
  reported exactly as loudly as before — the field is named, and a rewritten payload
  never describes the same as the one it replaced — and rollback still restores the
  real body, because restoration reads the snapshot rather than the preview. No
  operation writes to one of these fields yet; this lands first so that none can.

### Added
- **SVG images can reach the media library, safely** (REQ-0105) — `media-svg-upload`
  adds one SVG so Elementor's icon and image controls can point at it. The file that is
  stored is never the file that was sent: the document is rebuilt from an allowlist of
  drawing elements, and scripts, event handlers, embedded HTML, stylesheets, external
  references and entity declarations do not survive that rebuild. Everything removed is
  reported as a warning and the exact document that will be stored is shown in the
  preview, so what is approved is what will exist. It is the only path allowed to store
  markup — the ordinary upload and import operations still refuse SVG outright — and it
  asks for `unfiltered_html` as well as `upload_files`, because storing an SVG is closer
  to publishing markup than to uploading a photograph. The site's own upload permissions
  are untouched: nothing about the WordPress media screen changes.
- **Build, empty and create whole Elementor pages** (REQ-0104) —
  `elementor-document-build` replaces a page's entire layout with one you supply,
  `elementor-document-clear` empties a page, and `elementor-document-create` makes a
  new page for a layout to live on. All three refuse a layout using a widget this site
  does not have installed, and refuse any setting key the widget carrying it does not
  declare, rather than storing it and letting Elementor drop the text silently. Build
  and clear both preview, snapshot and roll back, and both refuse a request that would
  leave the page exactly as it already is instead of reporting a change that never
  happened. A created page is always a draft, so nothing an agent sends can put an
  unreviewed page in front of visitors, and it is a real Elementor page from the moment
  it exists — the layout you ask for reaches its page settings, and every other
  Elementor operation will work on it.
- **Elementor page settings, child order and element names** (REQ-0103) —
  `elementor-page-settings-get` and `elementor-page-settings-set` read and change the
  settings a page carries as a whole (its layout template, whether the title shows),
  `elementor-elements-reorder` sets the order of one element's children, and
  `elementor-element-label-set` names an element in the navigator. Page settings live in
  their own meta row, so the write snapshots that row and not the document — a rollback
  puts the settings back and leaves the page's content untouched. The write reaches a
  closed list of settings and merges into the stored row, so anything SiteHelm does not
  name survives it, while the read returns the whole row so an agent can see what else is
  there. A reorder demands the parent's complete list of children, so a request written
  against a page that has since gained one fails loudly instead of quietly guessing where
  the new element belongs.
- **The Elementor template library, as a first-class surface** (REQ-0102) —
  `elementor-template-list` and `elementor-template-get` read the templates the site has
  saved, `elementor-template-save` stores a document or a single element as a new one,
  `elementor-template-apply` inserts one into a page, `elementor-template-import` brings
  in a template this site did not produce, and `elementor-theme-template-create` makes an
  empty theme document. An apply re-mints every element id against the destination page
  and rebinds the styles that pointed at them, so the same template inserted twice does
  not collide with itself. Apply and import both check a template against the widgets this
  site actually has and name the missing ones, rather than writing a tree the page cannot
  render. A theme document is created with no display conditions, so it shows nowhere
  until `elementor-theme-conditions-set` says where.
- **Elementor 4 global classes** (REQ-0101) — `elementor-global-class-list`,
  `elementor-global-class-create`, `elementor-global-class-update`,
  `elementor-global-class-delete` and `elementor-global-classes-reorder` cover the
  reusable style classes Elementor 4 keeps site-wide: read them in cascade order, add one,
  rename one or merge style properties into it, remove one, and set the order they cascade
  in. The class set is one snapshotted unit, so every change is reversible as a whole. A
  write refuses while the editor is holding unpublished class changes instead of
  overwriting them, and the read reports that divergence as `inEditorSync`. A delete says
  how many documents wear the class before it is approved, and never edits a document — so
  restoring the class restyles every element that wore it.
- **Find a phrase everywhere on the site** (REQ-0092, Free half) — `content-search` names
  every post, page and custom post type whose title, content, excerpt or Elementor data
  mentions a phrase, with a per-field count, a plain-text excerpt of the first occurrence,
  and a link to each document. The phrase is matched whole, not split into words, so
  searching for an old company name does not return every page carrying the word "name".
  Results are filtered document by document against your own WordPress user's
  `edit_post` capability, so a search cannot become a way to read drafts you may not open.
  Elementor pages are found — their text lives in post meta, which WordPress's own search
  does not read — and reported at the document level, with `elementor-element-search`
  naming the individual elements inside one of them. A phrase JSON would store escaped is
  flagged `elementorExact: false` rather than presented as a complete answer, and a search
  broad enough to match most of the site stops at five hundred documents and says so.
- **WooCommerce is a module the console knows about** (REQ-0057, groundwork) — the
  Modules screen lists WooCommerce alongside the built-in nine, with its own permission
  level and its own requirement line, and the Pro screen names the eight operations it
  brings: products listed, read, created and updated (name, description, SKU, price, sale
  price, stock and categories), product categories listed, and orders and customers read.
  Orders and customers are read-only by design — SiteHelm will not rewrite a shop's
  financial record. The operations themselves ship in the SiteHelm Pro add-on; the free
  plugin carries the identifier, the console copy and the capability reservation so that
  an add-on can register them without the free plugin changing again.
- **Forms, listed and read** (REQ-0084, Free tier) — `form-list` names every form the
  site's form plugin holds with its embed shortcode, `form-get` reads one form's fields
  (name, type, required) exactly as the stored template declares them, and
  `form-entries-list` reads a form's recent entries where the plugin stores any — for
  Contact Form 7, which delivers each entry by email and stores none, the answer says so
  plainly instead of erroring. Entries gate on `manage_options` because a submission can
  carry a visitor's personal information. Everything is read-only: no form write, no
  entry deletion. Other form plugins arrive through the same provider seam as an add-on.
- **Five more SEO plugins speak the shared vocabulary** (REQ-0083) — All in One SEO,
  SEOPress, The SEO Framework, Slim SEO, and SureRank now serve the six existing SEO
  operations alongside Yoast SEO and Rank Math. The same field names read and write
  whichever plugin the site runs, the answer names its `provider`, and precedence
  across a multi-plugin site is fixed (Yoast, Rank Math, All in One SEO, SEOPress,
  The SEO Framework, Slim SEO, SureRank). A field a plugin has nowhere to store reads
  as null and a write to it promises null in the preview instead of failing after
  the fact.
- **Site settings, read and write** (REQ-0062) — `site-settings-read` answers the whole
  thirteen-field allowlist (title, tagline, timezone, date and time formats, posts per
  page, front page geometry, permalink structure, default comment and ping status,
  search-engine visibility) typed in one call, and `site-settings-set` changes any subset
  of it as one previewed, snapshotted, reversible change. Values are validated strictly
  at plan time — a timezone must be a real identifier, a custom permalink structure must
  keep every post's URL unique, a front page must be a published page — and nothing
  outside the allowlist is reachable. Changing the permalink structure flushes rewrite
  rules and warns that existing URLs change; turning search-engine visibility off warns
  that removal from indexes is not guaranteed.

## [0.6.0] — 2026-08-23

### Added
- **Three more Pro operations in the Tools-tab catalogue** — `content-seo-schema-get`,
  `content-seo-schema-set` and `content-seo-audit-fix` (SiteHelm Pro 0.2.0), so a site
  without the add-on sees them as locked rows in their groups.
- **Pro operations on the Tools tab.** The SiteHelm Pro operations are listed in the
  groups they belong to, each with a Pro tag. Without the add-on they appear as locked
  rows — full description, no switch — under one note with the one link to the Add-Ons
  page; with it installed but unlicensed the note says so and points at the Account page;
  with it active the tag stays and the rows are ordinary switches. No admin notice, and
  nothing on any other screen.
- The plugin now carries the Freemius SDK: an opt-in insights prompt on first run, and
  Account and Add-Ons pages under the SiteHelm menu. SiteHelm Pro is sold and licensed
  through it as an add-on; nothing about the free operations changes.

- **SEO scores and a site-wide SEO audit (REQ-0098, free part).** Two new reads for sites
  running Yoast SEO or Rank Math. `content-seo-score-get` reports one post's SEO and
  readability scores exactly as the plugin stored them (never recomputed, so the number
  matches the editor; Rank Math has no separate readability score and says `null`), plus
  a list of findings derived from the metadata — missing, too-short or too-long
  description, over-long title, missing focus keyword or one absent from the title, a
  published post kept out of search, a score under the caller's floor.
  `content-seo-audit` walks a page of posts (default 50, at most 100) and reports each
  one's scores and findings with a count of every finding across the page, detecting
  duplicate titles and descriptions within it; posts the caller may not edit are skipped
  and counted, not shown. The finding vocabulary is fixed and published in both output
  schemas.
- **Term SEO metadata (REQ-0098, free part 2).** `content-term-seo-get` and
  `content-term-seo-set` read and write a category's or tag's title, description,
  canonical, focus keyword and noindex in Yoast SEO (the `wpseo_taxonomy_meta` option)
  or Rank Math (term meta, with the robots directive list edited rather than replaced).
  Both admit on `edit_posts` and then ask the taxonomy's own edit capability, so a
  contributor cannot rewrite what a category archive tells search engines. The write
  is previewed, snapshotted and reversible like every other. 74 operations.
- **Extension points.** Three hooks let another plugin extend SiteHelm without touching it:
  `sitehelm_modules` (add a module), `sitehelm_register_operations` (add operations to a
  module) and `sitehelm_status_sections` (add a section to the Health tab). Every hook is
  additive and contained: nothing an add-on returns can remove a built-in module, and a
  throwing handler is logged rather than allowed to break the boot.
- **SiteHelm Pro: deep SEO (REQ-0098, Pro part).** Five Pro operations for sites running
  Yoast SEO or Rank Math, each gated by the licence first and `manage_options` second.
  `seo-settings-get` / `seo-settings-set` read and write an allowlist of the plugin's own
  settings — separator, knowledge-graph name and logo, default social image and
  breadcrumbs at site scope; title and description templates, noindex and sitemap
  inclusion per public post type — as one previewed, snapshotted, reversible change per
  scope, translating between the two plugins' option layouts (Yoast's separator codes,
  Rank Math's two-key robots rule). `content-seo-bulk-set` applies the `content-seo-set`
  fields to up to fifty posts at once as a single reversible change, refusing the whole
  set if one post is missing or not the caller's to edit. `seo-404-log-list` and
  `seo-redirection-list` page Rank Math's 404 monitor and redirections newest first (at
  most 200 a page), and say plainly when a Yoast site has no such tables or the Rank Math
  module is switched off. The free plugin is unchanged: the operation count stays at 74.
- **SiteHelm Pro foundation.** A separate add-on plugin (source in a private repository, not part of the
  free zip) with an offline-signed per-site licence key, a Licence card on the Health tab, and
  a gate every Pro operation checks itself. The free plugin carries no Pro file, no nag and no
  crippled operation.
- **A Home tab.** The console now opens on a plain-language summary: one sentence on how the
  week went, three tiles (changes this week, could not be done, undone), the last five things
  an app did, and a "Connect an app" prompt when nothing has happened yet.
- **Permission levels.** Each module card on the Permissions tab carries four buttons — Off,
  Read, Edit, Full — in place of the single on/off switch. Read allows only reads; Edit adds
  writes that are neither destructive nor high risk; Full allows everything. A module whose
  per-operation switches match no level reads "Custom", and a link leads to the Tools tab for
  fine-tuning.

### Changed

- **Tabs speak the owner's language.** Modules is now Permissions, Operations is Tools,
  Activity is History, Status is Health, and the Connect tab is "Connect an app" at
  `?page=sitehelm-connect` (the console's first page is now `?page=sitehelm`, Home).
- **History reads as sentences** — "claude-code changed the page *About us*" — with the
  operation id kept in small print underneath; the columns are When, What happened,
  Outcome, Took, Who, Undo.
- **The Tools tab opens with an "advanced" note** saying most owners only need the
  Permissions tab.
- **Tabs no longer stretch to fill the bar**, so the row can take more tabs and scrolls when
  it must.

- **The admin console's look.** A new design throughout: indigo accents, Geist headings,
  one white content panel under the tab bar, headline stat tiles, and a "Get help" menu in
  the app bar that links to the documentation, the changelog and the issue tracker.
- **The Operations screen lays its operations out as cards** — switch, name, description,
  module and required capability on each — in place of the table. Clicking anywhere on a
  card flips its switch, each tool's group can be collapsed, and "All on / All off" is now a
  segmented control.

## [0.5.0] — 2026-08-22

### Added

- **A switch on every Modules card** that turns the whole module's operations on or off
  together — the same switches the Operations screen shows one row at a time, stored in
  the same option, so a module half-switched off there reads "N of M operations on" here.
- **Per-operation switches on the Operations screen.** Every operation now has an
  on/off switch, with per-group counts, "All on" / "All off" per tool, and a sticky
  save bar. A switched-off operation leaves the catalogue, is refused with the
  same answer an unknown operation gets, and `system-operation-schema` will not
  describe it either; everything is on by default, including
  operations a later update adds. Stored in `sitehelm_disabled_operations`.
- **A one-time notice after activation** pointing at the Connect screen, shown to the
  first operator who can open the console and then gone.

- **A Site Health test** under Tools → Site Health that says whether client
  credentials reach WordPress on this server, with the .htaccess fix when they do not
  and a warning when application passwords are switched off.

- **Connect and Status links on the Plugins screen**, beside Deactivate, for the
  moment after activation.

- **Filter the Activity log by period.** Last hour, 24 hours, 7 days or 30 days,
  alongside the other filters; the period travels with the pager and the CSV export.

- **The Status screen now tests whether the Authorization header reaches WordPress.**
  A loopback to the endpoint with a login that cannot exist tells a server that passes
  the header through from one that strips it (Apache as CGI/FastCGI), which is the
  commonest reason a fresh credential is "wrong"; when it is stripped the screen gives
  the three .htaccess lines that fix it.

- **Export the Activity log as CSV.** The Activity screen's filter row now ends in
  an Export CSV link that downloads every row matching the filters shown, newest
  first, up to 10,000 rows (the file says so on its last line when it stops there).
  Cells that a spreadsheet would read as formulas are neutralised.

- **Set how long records are kept, on the Status screen.** The pruning window
  (1–365 days, default 30) that governs the activity log and the snapshots behind
  each rollback was previously only reachable by editing an option; it is now one
  number and a Save button under "Record retention".
- **A SiteHelm widget on the wp-admin Dashboard.** Whether writes are paused,
  how many credentials are issued, and the five most recent operations with their
  client and outcome — each linked to the console screen that explains it. It shows
  nothing to anyone who cannot open the console, and offers no controls.
- **Filter the Activity screen by client.** A "Filter by client" field joins the
  operation, correlation and outcome filters, and every named client in the actor
  column is now a link to everything that client did on the site.
- **See and revoke issued credentials on the Connect screen.** A new "Issued
  credentials" section lists every application password SiteHelm has created for
  the accounts you can act for — which account it acts as, when it was created,
  when it was last used — each with a **Revoke** button. Revoking cuts that client
  off at WordPress sign-in; nothing already recorded is touched. Only
  SiteHelm-named passwords are listed or revocable, and the handler enforces the
  same account boundary as minting.
- **Pause all writes from the Status screen.** A "Write access" section shows whether
  connected clients may change anything, with one button: "Pause all writes" puts
  the gateway in read-only mode so every write from every client is refused at the
  gate before any module runs; "Resume writes" lets them through again. Reads keep
  working either way, nothing already recorded is touched, and resuming never
  rewrites a mode the operator set some other way.
- **Roll a change back from the Activity screen.** Every applied row now carries a
  "Roll back" button beside its reference. The first click asks the change engine
  for a preview and shows a confirm panel — target, reference, a field-by-field
  Now / After rollback table, and any warnings — with nothing changed yet; "Roll
  back now" restores exactly what was shown, and the result is reported at the top
  of the screen in the engine's own words when it refuses. The restoration runs
  through the same dispatcher, capability checks, audit record and verification as
  a client-requested rollback, is recorded against the client `wp-admin`, and is
  itself re-restorable. The plan token never reaches the browser; it sits in a
  five-minute, per-user transient and is spent on the second click only.

### Changed

- **The console now says what a blocked module is waiting on, and which operations
  that blocks.** A Modules card that is not active names the plugin and the lowest
  version SiteHelm accepts ("Activate Elementor 3.0.0 or newer", "Update to Advanced
  Custom Fields 5.9.0 or newer") and links to Plugins; a module backed by WordPress
  itself points at Status instead. Every Operations row names its module, and a row
  the site cannot run yet is dimmed, marked "Not active", and counted in the verdict
  — the catalogue stays complete, so an operator sees which rows are promises. The
  Status verdict's "N modules are not active" now links to the screen that explains
  why. The version floors are read from the same constants the gateway enforces, so
  the two cannot drift.

### Fixed

- **Every string a request can carry now has an upper bound too.** Ninety-four of
  the catalog's string arguments already declared a maximum length; five did not,
  including the redirect target, whose limit the handler was already enforcing
  without ever publishing it. All five are now declared, from what the storage can
  actually hold rather than from a round number. With lists, maps and strings all
  bounded, no argument the gateway accepts is unbounded in size.

- **Every map a request can carry now has an upper bound too.** Six operations
  accept a free-form object whose keys are the site's own vocabulary — a widget's
  settings, a block's attributes, a typography entry — and none of them said how
  many members one request could carry. Elementor's known-key check is not that
  bound: it refuses names a widget does not declare, and it does not run at all
  when the widget type is unknown. `maxProperties` is now both applied by the
  validator and declared on all six, two of them from limits their handlers were
  already enforcing without publishing. An over-large object is refused whole.

- **Every list a request can carry now has an upper bound.** Eight arrays across
  `content-block-update`, `content-meta-update`, `content-terms-assign`,
  `elementor-theme-conditions-set`, `menu-item-create`, `menu-item-update` and
  `menu-items-reorder` accepted a list of any length, so their size was discovered
  by running out of time or memory rather than by being refused. One of them —
  the term identifiers inside `content-terms-assign` — sat one level down, inside
  an entry of another list. `elementor-theme-conditions-set` already enforced its
  limit in the handler and simply never published it; the schema now names the same
  constant. A new registry-wide test sweeps every input schema recursively and
  fails on the first array that declares no bound.

- **Five constraints the operation schemas declared are now actually applied.** The
  gateway validator applied `type`, `enum`, `minimum`, `maxLength` and the structural
  keywords, and silently ignored `minLength`, `maximum`, `pattern`, `minItems` and
  `maxItems` — 44 declarations across 22 files. A published schema is what an agent
  reads to learn what a site accepts, so a declared bound that is never checked is
  worse than an absent one: a well-behaved client stops checking for itself.
  `maxItems` was the only declared upper bound on array size anywhere in the catalog,
  so every batch operation accepted a list of any length and discovered the size only
  while walking it; an over-long array is now refused whole, before the walk. A new
  registry-wide test fails on the next keyword written into a schema that the
  validator does not apply.

- **A part-completed taxonomy assignment now says which taxonomies were already
  written.** `content-terms-assign` writes one taxonomy at a time, but a failure
  reported the same two completed steps whichever write it happened on — so an
  operator whose second taxonomy failed was told that nothing had changed, when the
  first had already been applied. The rollback record was always complete; only the
  account of it was not.
- **A menu name too long to belong to any menu is now refused before the lookup.**
  The four operations that take a `menu` argument — `menu-get`, `menu-item-create`,
  `menu-items-reorder`, `menu-location-assign` — accepted a string of any length. A menu
  is a `nav_menu` term, and all three ways to name one resolve against columns bounded at
  200 characters, so a longer string could never have matched one. It is now rejected by
  the argument schema, which says the bound, instead of by a not-found result after the
  search.
- **Importing from a URL now stops downloading at the size this site will actually
  accept.** The transfer was bounded by the plugin's built-in 8 MiB ceiling on every
  site, so a site configured to accept 2 MiB still pulled up to 8 MiB across the
  network and held it in memory before refusing it for size — four times the transfer
  and four times the peak memory for a refusal that was never in doubt. Both the wire
  limit and the check after it now use the effective cap, the smaller of the built-in
  ceiling and the site's own upload limit. A site reporting no positive limit still
  falls back to the built-in ceiling rather than to zero.
- **Refusals on the import path no longer say "uploaded".** Six messages shared by
  `media-upload` and `media-import` described content that was fetched from a URL as
  though the caller had uploaded it, which reads as a refusal of some other request.
- **The import operation states the punycode requirement up front.** An
  internationalised domain name must be supplied already in its `xn--` form — the
  address is never converted, because the name checked and the name dialled would then
  be two different strings. That was learnable only from a refusal; it is now in the
  `url` field's own description.

## [0.4.0] — 2026-08-19

### Added

- **Activity now reads as a record rather than as a dump.** The details column stated the
  raw redacted JSON the audit store keeps; it now reads in English — "post title 21 → 36",
  or simply "roles changed" where the recorded sizes are equal and therefore say nothing.
  No unit is invented: the store deliberately records a size and never a value, and a
  character count and an array length are the same integer there. A summary that does not
  parse is still shown exactly as stored, because an unreadable record is a fact worth
  seeing rather than hiding behind a friendly nothing.
- **Every operation is timed, and the time is shown.** Each record now carries how long it
  took, in milliseconds under a second and in seconds above it. Records written before
  this release have no measurement and show a dash rather than a zero.
- **Activity can be narrowed to one outcome.** A closed list — applied, restored, the
  three failures, and still-running — alongside the existing operation and correlation
  filters, and it survives into the pagination links like the others.

- **User administration — `user-list` and `user-role-set`.** The people who can reach a
  site can now be read and, one account at a time, re-roled. The listing answers accounts
  by role or search term, newest registration first, and carries the role slugs this
  particular site has registered — a roster no fixed list could publish, since a store or
  a membership plugin adds its own. The write replaces one user's roles with a single
  registered slug. It refuses four things outright rather than letting a preview promise
  them: an unregistered slug, the acting user's own account, the last remaining
  administrator, and a multisite super admin — each of them a way to lock a site out of
  its own admin. Promoting someone to administrator is permitted and warned about, as is
  the collapse of a multi-role account down to the one role you sent; the snapshot keeps
  every role held beforehand, so a rollback restores the whole set rather than the first
  of them. Seeing the roster and changing it are two separate capabilities, and neither
  operation accepts the other's, so a client allowed to audit access cannot grant it. The
  target-bound edit check runs against the specific account in the preview, the apply, and
  the rollback. No password hash, reset key, or session token is reachable through either
  operation.
- **Comment moderation — `comment-list`, `comment-status-set`, and `comment-reply`.** The
  comment queue can now be worked: listed by post, status, or search term newest first;
  one comment moved between approved, pending, spam, and trash; and a reply posted under
  a comment as the acting account. All three gate on the comment-moderation capability
  alone, so a moderator with no editing rights can use them and a capability meant for
  posts is never demanded alongside it. Nothing here deletes anything — spam and trash
  are reversible statuses on a row that stays where it is, every status write is
  snapshotted and rolls back, and the value that would perform a permanent deletion is
  not in the vocabulary at all. The status write goes through the same WordPress function
  the moderation screen does, so marking a comment as spam still records the prior status
  for WordPress's own unspam and still tells the anti-spam plugins what was decided. Two
  refusals exist because performing the write would produce a result with a hidden expiry
  date: a comment whose parent post is in the trash is refused with the real fix named,
  because WordPress owns that comment's status until the post returns; and a reply under
  a spam, trashed, or post-trashed parent is refused, because WordPress would silently
  reparent it to the top of the thread. Replying under a pending parent is allowed but
  warns that the parent is still awaiting moderation, so the reply does not sit invisible
  under an invisible comment. The listing defaults to approved plus pending together
  rather than the moderation queue alone — an empty queue reads as "nothing to do", which
  is the wrong answer to "what is on this post" — and spam and trash appear only when
  asked for by name. The commenter's IP address is never reported.
- **SEO metadata — `content-seo-get` and `content-seo-set`.** One post's search-engine
  metadata can now be read and written on a site running either Yoast SEO or Rank Math,
  through one vocabulary that names neither: `title`, `description`, `canonical`, the four
  social fields, and the search-visibility directives. The answer carries a `provider`
  saying which store it came from, and that is the only place a plugin is named. If both
  plugins are installed Yoast serves the site, by a fixed precedence, so a write always
  lands in the store the read that planned it came from. The visibility directives are
  tri-state — `null` means the post says nothing and the plugin's own default decides,
  which is a different state from an explicit instruction to index — and Rank Math's
  inability to store an explicit *follow* is declared in the preview rather than
  discovered at verification. Writing a flag on Rank Math merges into the directive list
  it already stores, so `noarchive` or `nosnippet` set in the plugin's own screen survives
  a `noindex` change. The two social images are read-only, because both plugins keep an
  identifier and a cached URL that a partial write would leave disagreeing. A snapshot
  records which plugin it was taken from, so a rollback on a site whose SEO plugin has
  since changed is refused rather than replayed into a store the site no longer renders
  from.
- **Elementor theme builder — `elementor-theme-template-list` and
  `elementor-theme-conditions-set`.** The header, footer, archive, search, 404, singular, and
  product templates a site has built can now be listed with the display conditions each one
  stores, and one template's conditions can be replaced as a whole rule — `include/general`
  for the whole site, `exclude/singular/page/12` to carve out a page. The list is replaced
  whole rather than edited entry by entry, because the conditions on a template are one
  indivisible rule; that makes the write idempotent and the preview a complete statement of
  where the template will display afterwards. An empty list is legal and detaches the
  template without deleting it. A condition that does not parse refuses the whole request,
  so a half-applied rule is not reachable. The write discards Elementor's resolved condition
  map in the same step that stores the rule, so the front end stops serving the previous
  header immediately — without that, the stored value is correct and every re-read agrees
  while visitors still see the old one. Rolling back distinguishes a template that had no
  conditions from one that had an empty list, so a restore of a never-configured template
  removes the row rather than storing an empty one. Both operations omit templates the caller
  may not edit, and the write requires `edit_theme_options` — the capability Elementor puts
  on site-wide settings — as well as edit rights on the template itself.

- **Redirects — `redirect-list`, `redirect-set`, and `redirect-delete`.** A retired URL can
  be pointed at its successor, so the traffic and the ranking the old address earned survive
  a rename, and a page that is simply gone can be marked `410` instead of answering `404`
  forever. `redirect-set` creates or replaces in one call — the target is the path itself, so
  sending the same redirect twice leaves one row. The visitor's query string is carried over
  by default, and a target may carry its own, which wins where the two name the same
  argument. Redirects are served on `template_redirect`, ahead of the front-end request, and
  never on an administration, cron, or REST request. A redirect that would send a visitor
  back to the path they asked for is refused both when it is written and when it would be
  served, because a rename months later can turn a good redirect into a loop. A site holds
  at most 500, and `redirect-list` reports the count beside the capacity so the bound is
  visible before a write refuses it. The whole table is one stored value, so rolling either
  write back restores the table as it stood at apply — any other redirect changed in the
  interval is reverted with it, and both operations say so in their descriptions.

- **`content-links-check`**, which reports the links in one content item and says which of
  this site's own links no longer lead anywhere. A rename leaves the site pointing at its
  old paths from inside its own content, and nobody sees those until a visitor clicks one.
  Every answer comes from this site's database: a link to another host is listed and left
  `unchecked`, because a content operation that makes outbound requests is a content
  operation that can be pointed anywhere. This site's own links are resolved the way a
  visitor's request resolves them — to a post, to the redirect that catches the path, or to
  nothing at all. A link a redirect already catches is reported as a redirect rather than
  hidden among the working ones, because a hop is a net, not a repair. `brokenOnly` trims
  the list to what needs fixing while the counts keep describing the whole page.

- **`content-blocks-get`**, which reads the block structure of a page instead of its text.
  Called with an identifier alone it returns the outline: every block, its address, its
  depth, the *names* of its attributes, and a short plain-text preview — enough to find the
  paragraph you meant without spending a context window on the ones you did not. Called
  with an address as well, it returns that one block in full: attribute values and inner
  markup, with its descendants still in outline form. The outline also reports whether the
  document can be rewritten a block at a time, so a client learns that before it plans a
  change rather than when the change is refused.

- **`content-block-update`**, which changes the attributes or the inner markup of one block
  and leaves every other block byte-identical. Because `post_content` is one column, a
  block edit is unavoidably a whole-document write — so the operation reproduces the
  document from its own parse and refuses, without writing, if that reproduction is not
  byte-identical to what is stored. It also requires the caller to name the block it expects
  at the address, since an index path cannot notice that the page was re-ordered since the
  outline was read, and it replaces inner markup only on a block with no inner blocks and a
  single chunk of markup, rather than guessing where text belongs among a block's children.
  Like every other write it previews first, snapshots the prior columns, verifies the result
  by re-reading it, and can be rolled back.

- **`elementor-composition-get`**, which says what an Elementor page contains at a size that does
  not grow with how much it contains. It returns the page's totals, a census of widget types and
  container types by how often each is used, and one entry per top-level band naming that band's
  identifier, how many elements sit inside it, and the widget types found anywhere beneath it —
  enough to decide which band to read in full, without reading all of them first. It also reports
  how many elements carry no stored identifier, which is exactly how much of the page no write can
  address; reading a full tree node by node left a client to notice that for itself. A page whose
  stored data cannot be read is refused here exactly as the full read refuses it, because a cheap
  digest of a damaged page is the one wrong answer a client would act on without hesitating.

### Fixed

- **The rollback reference in Activity can be read and taken.** It was the one string on
  the screen an operator has to carry somewhere else, and it was the one being clipped.
  The cell now narrows the value visually while keeping it whole in the page, with the
  full reference on hover and a copy control in the row — so what is copied is always the
  entire reference, never the part that happened to fit.
- **Secondary text in the console meets AA contrast.** Table headings, card identifiers,
  stat labels and hints shared a grey that measured about 4.4:1 against the tinted
  surfaces it was used on, below the 4.5:1 body-text threshold. It is now a darker tone
  measuring about 5.3:1 there and 5.6:1 on white.
- **Three reads now ask for their own permission.** `audit-list`, `system-environment` and
  `image-size-list` each declared a capability and then left the asking entirely to the
  request path that calls them. That path does ask, so nothing was exposed; but a handler
  reachable only one way is a guarantee about today rather than a guarantee. All three now
  check for themselves, and `audit-list` checks before it looks at its storage, so a caller
  who may not read the change log no longer learns whether the log exists. No refusal names
  the capability it wanted, because a message that does is a way to enumerate what a
  credential is missing.
- **The Connect screen's by-hand check now runs on Windows.** The stdio card offers a command
  to run the bridge yourself and see whether it connects; it was written in one shell dialect
  only, and in PowerShell that spelling is not a command that fails — it is a parse error. The
  same check is now offered in both dialects, each launching the same bridge with the same
  credential as the configuration above it.
- **Rollback now works for redirects, comments and user roles.** Reversing a change was built
  when every change in the plugin belonged to a post, and it recovered the target by reading a
  post out of the reference. Writes to redirects, to comment status and to a user's roles have
  shipped since, and each of them handed back a rollback reference that could not be redeemed:
  the reference was accepted, the target was looked for as a post, and the answer was that no
  such target existed. The offer was real; the redemption was not. Each of those writes now
  takes its own changes back — it recognises its own references, says what restoring them would
  actually produce, and refuses at preview when the recorded state can no longer be reproduced
  (a role the site has since unregistered, a comment whose parent post is in the trash) rather
  than promising a restoration that the apply would reject. Reversing a change also asks the
  same permission the original change asked: reversing a comment moderation now requires
  moderation rights and reversing a role change now requires the right to promote users, where
  before the rollback's own weaker check would have been the only gate had these references ever
  resolved. Reversing a post edit is unchanged in every respect, including the order in which it
  reports refusals.

## [0.3.0] — 2026-08-17

### Added

- **`media-resize`**, which brings an oversized image within a width and a height you name,
  so the size the site actually serves fits the sizes the theme renders. The original file is
  never overwritten and never deleted: the reduced image is written to a new file beside it,
  the media item is re-pointed at the new file, and the untouched original stays reachable
  through the same metadata WordPress uses for its own scaled uploads. A second reduction
  still reads the true original rather than the previous reduction, so detail is not thrown
  away twice. Rolling back points the item at the file and the metadata the snapshot recorded
  and then checks that it landed. An image already within the requested maximum is refused
  rather than re-saved, so a repeated request cannot reduce it twice.
- **`elementor-elements-update`**, which changes the settings of several elements on one
  Elementor page as a single reviewed change. One preview covers all of it, one save writes
  it, and one rollback reference undoes it. Every entry is checked against the page before
  anything is written, so an entry naming a setting the widget does not declare — or a
  layout element, or an element that is not there — refuses the whole request and leaves
  the page untouched, rather than landing the entries before it and stopping. A refusal
  names which change in the request caused it. Two entries naming the same element are
  refused rather than resolved by order.
- **A stdio bridge, shipped with the plugin.** AI clients that cannot open an HTTP connection
  launch a local subprocess and speak over its stdin and stdout instead. `bridge/sitehelm-bridge.mjs`
  is that subprocess: no dependencies, Node 18 or newer, and it forwards each message to the
  site's endpoint unchanged. The Connect screen now hands out a config that runs it, so the code
  on the operator's machine is the code that was reviewed and installed here rather than whatever
  a package registry serves at launch. The credential travels in the config's `env` block instead
  of on a command line, which every process on the machine can read. The public `mcp-remote`
  bridge is still offered beneath it, for a client running somewhere the plugin's files are not.
- **`system-operation-schema`**, a fifth system read that returns one named operation's full
  input and output schema on demand. An operation the caller cannot see does not surrender its
  schema: an unknown name and a hidden one are refused identically, so the answer cannot be
  used to map the site's surface area.
- **A retired-domain guard on writes.** A request that reaches the site at an address the site
  no longer answers as is refused for every write, with a remediation naming the address to
  reconnect at. Reads stay available on purpose, so an operator whose connector points at an
  old domain can still run the diagnostics that say so. A request arriving with no `Host`
  header at all — WP-CLI, cron, an internal dispatch — is not treated as a mismatch, and
  neither is the `www.` spelling of the site's own domain.

### Changed

- **Dispatcher catalogs no longer carry each operation's `inputSchema` and `outputSchema`.** A
  dispatcher holding a dozen operations spent most of a client's context window on schemas for
  operations it would never call. Each entry keeps its usage example, which states the argument
  shape concretely, and the catalog names `system-operation-schema` as the way to fetch one full
  schema when it is actually needed. A client that read schemas straight from the catalog must
  now ask for them.
- Catalogs list a write that arrived on a retired host as unavailable with the new blocking
  reason `retired_host`, rather than advertising it as available and refusing it on use.

## [0.2.1] — 2026-08-17

### Added

- **Modules screen** — one card per capability pack, stating whether it is active, the
  version SiteHelm detected, and how many operations it actually registered this request.
  A module that is not active is dimmed as well as badged, so a wall of cards does not have
  to be read badge by badge to find the one that is wrong.
- **Eight more clients on Connect**, taking it from three to eleven: Claude Desktop, Claude
  on the web, VS Code, Codex CLI, Antigravity, OpenClaw, Hermes, and any stdio-only client
  over the public `mcp-remote` bridge. Each carries the config in the shape that client
  actually reads — including the `servers` object VS Code wants rather than the `mcpServers`
  object everything else wants, and a config fragment for OpenClaw rather than a whole file
  that would overwrite settings unrelated to SiteHelm.
- **A request you can run to prove the endpoint answers**, offered on Connect, because "it
  does not work" is almost always the wrong URL, a stripped `Authorization` header, or a
  revoked password — and one request separates those without involving a client at all.

### Changed

- The admin area is now a five-tab console under one menu entry rather than a single page.
- **Connect can create an Application Password for another account** you have permission to
  edit, so an agency can hand a client's site a credential without signing in as them. The
  picker offers only accounts you may act for, and the request is re-checked when it is
  submitted rather than trusted from the form.
- **Status no longer repeats the module table.** It reports the count and points at Modules.

### Fixed

- A module whose plugin is installed but deactivated now reads as **not active** rather than
  **not installed**. Presence is detected from loaded constants and classes, which cannot
  tell those two apart, and the old wording sent operators off to reinstall what they had.
- A module SiteHelm never detected a version for omits the version line instead of printing
  "detected" followed by nothing.

## [0.2.0] — 2026-08-16

### Added

**Admin console** — one top-level **SiteHelm** menu with four screens, replacing the
"connect it by hand from the documentation" install.

- **Connect** — states the MCP endpoint, creates an Application Password in place and shows
  it exactly once, and renders a ready-to-paste configuration for Claude Code, Cursor, or
  any other MCP client. Warns when the site is not on HTTPS, and when Application Passwords
  are disabled explains why rather than offering a button that cannot work. (REQ-0074)
- **Activity** — every operation a client has performed, newest first, with its target,
  outcome, actor and rollback reference. Filterable by operation or correlation id, paged.
  It states a rollback reference rather than offering an undo button, so a rollback stays a
  deliberate act performed through the gateway.
- **Status** — which modules are active, inactive or version-blocked, the detected version
  of each integration, whether the ledger tables exist, and the environment SiteHelm is
  running in. Storage being unavailable overrides the module verdict, because nothing can be
  recorded without it.
- **Operations** — the full catalogue of what a connected client can ask this site to do,
  grouped by tool, in contract order, each marked read or write and badged when it requires
  preview, is destructive, or is high risk. Filterable client-side, and with scripting off
  every operation stays on the page.

The console is read-only apart from the single button that mints a credential. It adds no
options screen, no dashboard widget and no cron jobs.

## [0.1.0] — 2026-08-16

First release. The complete V1 surface: **51 operations** across **11 MCP dispatchers**, a
two-phase write pipeline, a change ledger, and rollback.

### Added

**Gateway**

- MCP over JSON-RPC 2.0 at the WordPress REST route `sitehelm/v1/mcp`, speaking protocol
  version `2025-06-18`. Handles `initialize`, `notifications/initialized`, `ping`,
  `tools/list`, and `tools/call`.
- Eleven dispatchers instead of one tool per operation, so a client's tool list stays small
  and each catalogue is fetched on demand.
- Authentication through WordPress Application Passwords; every operation runs a real
  capability check against the authenticating user before any target is looked up.

**The two-phase write**

- Every write is previewed first. The preview returns the exact after-state and a
  single-use plan token; applying without a token is refused, and so is reusing one,
  replaying an expired one, or presenting one whose arguments have since changed.
- Pre-change state is captured to a snapshot, the write is applied, and the result is read
  back and compared with what the preview promised. A disagreement is reported as
  `VerificationFailed` with `completedSteps` saying how far the operation got.
- A change ledger records what changed, when, and by whom, with a rollback reference where
  the change can be reversed.

**Content** — read one item, list with filters, list taxonomies; create, update, set
status, set the featured image, write registered meta, assign terms, trash (reversible,
never a permanent delete), and apply a rollback.

**Media** — read an attachment, list the library, list registered image sizes; upload from
supplied bytes, import from a URL, update alt text and captions, attach to a post.

**Menus** — list menus and their theme locations, read a menu's full item tree; create and
update items, reorder and re-parent a tree, assign a menu to a location.

**Elementor** (3.0.0+, optional) — list and read documents, read one element, search a
document by element type, text, or setting key, report which widget types the site actually
has, return a widget's control schema, and read the active kit's global colour and
typography tokens. Writes: add, update, move, duplicate, and remove elements, update a
widget's settings against its control schema, and update the global colour palette and type
styles site-wide. The stored document is edited directly and the generated CSS is flushed
afterwards, so changes appear on the front end without opening the editor.

The global-token writes address the active kit, so they gate on `edit_theme_options` — the
capability Elementor itself puts on the kit document. An update **merges** into the
addressed entry, so setting a colour does not erase its title, and kit settings outside the
palette are untouched. Typography setting names are validated by shape rather than against
a fixed allowlist, so a control added by a newer Elementor is not refused; an entry's own
`_id` stays unreachable, so a write cannot re-point a token that published pages already
reference. Element search names the setting keys that matched, never the values they hold.

**Fields** — ACF (5.9.0+) and Meta Box (5.3.0+), both optional. List groups, list fields,
read a value, write a value. Values are normalised per field type before writing and
verified by reading back; a field's formatted display value is never recorded as if it
were the stored value, so a restore puts back what was really in the database.

**Diagnostics** — confirm the connection and who is authenticated, report the WordPress and
PHP versions with registered post types and taxonomies, and report the health of every
optional integration as `Active`, `Inactive`, or `VersionBlocked`.

### Security

- `media-import` resolves and validates the host before connecting; private, loopback,
  link-local, and reserved ranges are refused; every redirect hop is re-validated and
  re-pinned; the resolved address is pinned so the connection cannot be re-pointed between
  the check and the fetch; the wire read is capped; and the refusal message is deliberately
  digit-free so it cannot be used as an SSRF oracle.
- No response envelope carries a stack trace, filesystem path, SQL fragment, database error
  string, authorization header, or resolved IP address. Field names may appear; field
  values never appear in a warning or a refusal.
- All SQL goes through `$wpdb->prepare`, and table names come from the installer rather
  than a hardcoded prefix.

### Permanently excluded

Unrestricted SQL, unrestricted filesystem access, and irreversible permanent deletion are
out of scope by design and will not be added. Code ships only through the Pro Code module's
guard, and nothing SiteHelm stores ever executes during its own request. See
[ROADMAP.md](ROADMAP.md).

[0.11.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.11.0
[0.10.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.10.0
[0.9.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.9.0
[0.8.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.8.0
[0.7.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.7.0
[0.6.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.6.0
[0.5.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.5.0
[0.4.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.4.0
[0.3.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.3.0
[0.2.1]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.2.1
[0.2.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.2.0
[0.1.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.1.0
