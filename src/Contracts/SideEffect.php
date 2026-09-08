<?php
/**
 * Consequences an operation carries beyond the change it promises.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * What approving an operation does BEYOND the fields it promises to change.
 *
 * The rest of a definition answers "what will be different afterwards": the
 * description says it in words, the risk level sizes it, the policies say
 * whether it can be previewed and undone. None of them says what the write
 * costs on the way past. Saving a page again changes nothing in the page and
 * still runs every plugin that listens for a save. Dropping a cache changes
 * one stored value and makes the next visitor wait. Those are the facts a
 * caller wants BEFORE it picks an operation, and until now the only place
 * they were written down was the preview warning — which arrives after the
 * choice has already been made.
 *
 * THE LINE THAT DECIDES WHAT BELONGS HERE. A definition is static. It is
 * built once, at registration, with no arguments in hand, and it is the same
 * for every caller on every site. So a fact may be declared here only if it
 * is true EVERY time the operation runs. "This image has no library entry",
 * "that field definition could not be read", "the value already matched, so
 * nothing changed" are all true of one call and false of the next; they are
 * preview warnings and they must stay preview warnings. Moving an
 * argument-dependent caveat into a definition turns a precise statement into
 * a permanent one, and a warning that is always shown is a warning nobody
 * reads.
 *
 * WHY THE FIRST TWO CASES ARE SEPARATE. Running somebody else's code during
 * this call and changing which code runs on the NEXT call are different
 * promises with different remedies. A plugin update does both. Saving a page
 * again does only the first, and that single fact is the reason this enum
 * exists: it is the whole cost of the operation and it had nowhere to live.
 *
 * THE CASE THAT IS NOT HERE. "Widens access" was drafted and dropped. The
 * operation it was drafted for sets a user's role, and setting a role lowers
 * one as often as it raises one — so the fact is true of some calls and false
 * of others, which is the definition of a preview warning. No other operation
 * in either repo widens access every time it runs. A case with no honest home
 * is worse than a missing one: it invites the next author to declare it
 * approximately, and one approximate entry makes the whole list advisory.
 *
 * Absence is not a claim of safety. An empty list means the operation
 * declares none of these six, not that it has been audited and found free of
 * consequence. Read `risk` for size and `isDestructive` for what cannot be
 * recovered; this enum is about kind, and it never repeats what those two
 * already say — which is why "the deletion is permanent" is not a case here.
 */
enum SideEffect: string {
	case RunsInstalledCode      = 'runs-installed-code';
	case ReplacesRunningCode    = 'replaces-running-code';
	case SlowsTheNextVisit      = 'slows-the-next-visit';
	case ChangesWhatVisitorsSee = 'changes-what-visitors-see';
	case LeavesReferencesBehind = 'leaves-references-behind';

	/**
	 * The consequence in a sentence, for a caller to show a person.
	 *
	 * A slug on its own tells an operator nothing. Every surface that reports
	 * a side effect reports this alongside it, so the wording lives here once
	 * rather than being re-invented by each caller.
	 *
	 * @return string The sentence.
	 */
	public function sentence(): string {
		return match ( $this ) {
			self::RunsInstalledCode      => 'Approving this runs code the site owner installed, not only code SiteHelm wrote, so how long it takes and what else it touches depend on the plugins on this site.',
			self::ReplacesRunningCode    => 'Code that runs on this site is written or removed, so later requests are served by something different from what serves them now.',
			self::SlowsTheNextVisit      => 'Something the site had already worked out is discarded, so whoever asks for it first waits while it is worked out again.',
			self::ChangesWhatVisitorsSee => 'The change reaches the public site immediately; it is not held back for an editor to publish.',
			self::LeavesReferencesBehind => 'Other parts of the site may still point at what this changes, and those references are not updated with it.',
		};
	}
}
