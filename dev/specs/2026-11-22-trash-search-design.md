# Trash search (1.3.2)

Approved 2026-11-22. Forum: Gemini23, "Can a search option be added to trash" and "How to
recover image from trash?" (10–11 Nov 2026, both unanswered).

## Problem

The Trash view has no search. With thousands of trashed files, finding the one or two worth
restoring means paging through everything, and core empties the trash after
`EMPTY_TRASH_DAYS` (30 by default) whether anyone looked or not. The second thread shows the
Trash view itself is hard to find: core's Media Library hides attachment trash unless
`MEDIA_TRASH` is set, and ours sits behind a small link on the Unused tab.

## What changes

- The Unused view's filter bar (filename/title, media type, upload date range) also shows on
  the Trash view, with the same fields and the same matching rules.
- The filter WHERE clause moves into one helper on `UNMAM_Database`, used by both
  `get_unused_attachments_detailed()` and `get_trashed_attachments()`. One copy, so the two
  views cannot drift.
- **While a filter is active on the Trash view, Restore All and Empty Trash are hidden.**
  Both act on the whole trash, not on what is shown, and Empty Trash next to a three-row
  result is a trap. The Unused view already hides Trash All for the same reason. Restore
  Selected and Delete Permanently stay, since they only touch ticked rows. A line under the
  filter bar says to clear the filter for the whole-trash actions.
- Switching between Unused / Excluded / Trash drops the filter params. Before this, a filter
  set on Unused silently carried into Excluded, which has no filter bar to show it.
- Trash rows get an "Auto-delete" column: `_wp_trash_meta_time` + `EMPTY_TRASH_DAYS`. Blank
  when the meta is missing, since `wp_scheduled_delete()` only purges rows that have it.
  Overdue rows (cron has not run yet) read "Any time now".
- The "In Trash" stat card links to the Trash view.

## Not changing

- Excluded view gets no filter bar. Out of scope.
- No AJAX or live search. Nothing else in the plugin works that way.
- No change to what counts as used. This touches listing only, never the scanner.

## Testing (FLP)

Throwaway attachments with a `unmamtrash-` prefix, trashed, and deleted after.

- Search by name, by type, by date; total and pagination keep the filter.
- Filtered: Restore All and Empty Trash absent, Restore Selected restores only ticked rows.
- Unfiltered: both whole-trash buttons back.
- Unused view filters behave exactly as before (same counts for the same query).
- View switch drops the filter; Clear returns to the same view.
- Auto-delete date matches trash time + 30 days; blank without the meta; "Any time now" when
  overdue.
- Restore FLP and diff against the baseline in `dev/TESTING.md`.
