# CRM Lead Management — Functional Requirements

> This document captures confirmed business rules and design decisions for the DNC CHITS CRM module.
> Keep this updated as decisions are made. Use as the source of truth during Laravel implementation.

---

## 1. Lead Mandatory Fields

| Context | Mandatory | Optional (can be updated anytime) |
|---|---|---|
| Creating a lead (UI form) | Name, Mobile | Branch, Employee, Source, Date, Flag, all business/income fields |
| CSV Import | Name, Mobile (per row) | Everything else |
| CSV Import — before upload | Branch, Employee | Source (defaults to "CSV Import") |

- Branch and Employee during import are the **default assignment** for all imported rows.
- They are **independent** — a cluster head can assign leads to any employee from any branch.
- Individual leads can be reassigned to a different branch or employee after import.

---

## 2. Lead Sources

| Value | Label |
|---|---|
| `walkin` | Walk-in / Referral |
| `field` | Field Marketing Event |
| `online` | Online / Social Media |
| `import` | CSV Import (from external marketing tool) |

---

## 3. Lead Status Lifecycle

```
new → in_progress → converted
                 → dead
```

| Status | Meaning |
|---|---|
| `new` | Just entered, no interaction yet |
| `in_progress` | At least one interaction logged; follow-up ongoing |
| `converted` | Subscriber record created and linked |
| `dead` | Permanently closed; not interested |

- Status transitions to `in_progress` automatically when first interaction is logged.
- Status transitions to `converted` when "Convert to Subscriber" is completed.
- `dead` can be set manually; a reason should be logged as an interaction note.

---

## 4. Flag System (Lead Rating)

| Flag | Colour | Meaning |
|---|---|---|
| `green` | 🟢 Green | High potential — capable and clearly interested |
| `yellow` | 🟡 Yellow | Uncertain — needs more follow-ups, not yet committed |
| `red` | 🔴 Red | Low priority — not ready or unlikely to convert soon |

- Flag is set at lead creation (default: green).
- Flag can be updated with each interaction.
- Red-flagged leads are still kept and re-contacted in future; they are NOT dead leads.

---

## 5. Business & Income Fields (All Optional)

These fields come from the existing marketing tool and help assess the subscriber's financial profile.

| Field | Type | Notes |
|---|---|---|
| `income_source` | dropdown | Salary/Job, Own Business, Agriculture, Pension, Rental Income, Others |
| `monthly_income` | dropdown | Below ₹10K / ₹10K–₹25K / ₹25K–₹50K / ₹50K–₹1L / Above ₹1L |
| `business_name` | text | Name of business if applicable |
| `business_place` | text | Location / visit place |
| `chit_value` | dropdown | Proposed chit scheme value: ₹25K / ₹50K / ₹1L / ₹2L / ₹5L / ₹10L |
| `ref_name` | text | Name of person who referred this lead |

---

## 6. Interaction Log

Every touchpoint with a lead must be logged as an interaction.

| Field | Type | Notes |
|---|---|---|
| `type` | enum | `phone`, `inperson`, `whatsapp`, `email` |
| `date` | date | When the interaction happened |
| `time` | time | Time of interaction |
| `by` | string | Employee who made contact |
| `outcome` | text | What was discussed |
| `flag_after` | enum | Flag status set after this interaction |
| `follow_up_date` | date | Next follow-up scheduled date |
| `follow_up_note` | text | Reminder note for next follow-up |
| `fu_done` | boolean | Whether this follow-up was completed |

- Logging an interaction should update `lead.status` to `in_progress` if it was `new`.
- `flag_after` updates the lead's current flag.
- If a follow-up date is set, it appears in the employee's follow-up queue.

---

## 7. Lead Number Auto-generation

Format: `{BRANCH_CODE}-{5-DIGIT-SEQUENCE}`

Examples: `ARN-02783`, `TVN-00001`, `BCE-00042`

- Sequence is **per branch** — each branch has its own counter.
- Auto-incremented from the highest existing lead number for that branch.
- Leads imported via CSV get auto-generated lead numbers (even if source CSV had lead numbers).

---

## 8. CSV Import Rules

### Pre-import (mandatory selections)
- **Branch** — which branch owns these leads (mandatory)
- **Employee** — responsible employee for follow-up (mandatory)
- Branch and employee are **independent** — no constraint that employee must belong to the branch

### CSV parsing
- First row must be headers (column names)
- Column names are matched **case-insensitively**; spaces, underscores, and hyphens are normalised

### Mandatory columns in CSV
- `Name` (or: Subscriber Name, Full Name)
- `Mobile` (or: Phone, Phone Number, Mobile No)

### Supported optional columns (auto-detected)
| CSV Column Name(s) | Maps To |
|---|---|
| Business Name, Business_Name | `business_name` |
| Business Place, Visit Place | `business_place` |
| Income Source | `income_source` |
| Monthly Income, Monthly Source | `monthly_income` |
| Proposed Chit Value, Chit Value, Scheme Value | `chit_value` |
| Reference Name, Ref Name, Referred By | `ref_name` |
| Flag, Lead Rating, Rating | `flag` (green/yellow/red) |
| Remainder Date, Follow Up Date, Next Call | `follow_up_date` |
| Remainder Note, Follow Up Note | `follow_up_note` |
| Notes, Remarks, Comments | `notes` |
| Email, Email Address | `email` |
| Source, Lead Source | `source` (overrides default if present) |

### Duplicate handling
- A row is considered a **duplicate** if the mobile number already exists in the system.
- Duplicates are **skipped** — they are shown in the preview with a warning but not imported.
- No merge/update of existing leads via CSV import (update is done through the lead detail UI).

### Validation
- Rows missing both name and mobile are skipped with an error shown in preview.
- All other fields: blank = use default or leave empty; no validation errors.

### After import
- All imported leads default to status `new`, flag `green` (unless CSV specifies otherwise).
- Imported leads get the branch and employee selected in Step 1.
- Import date (`input_date`) = today's date.

---

## 9. Conversion Flow (Lead → Subscriber)

1. Branch staff clicks **Convert** on a lead detail page.
2. A form pre-fills subscriber details from the lead (name, mobile, branch).
3. Staff can edit before saving.
4. Optional: KYC fields (PAN, Aadhaar, GST) — can be completed later.
5. On submit:
   - Creates a `Subscriber` record in rosca-digital.
   - Sets `lead.subscriber_id` = new subscriber's ID.
   - Sets `lead.status` = `converted`, `lead.converted_at` = today.
6. Staff is redirected to the subscriber profile to enroll in a chit group.
7. The lead record is **kept** (not deleted) and shows a "Converted" badge with a link to the subscriber.

---

## 10. Access Control (Planned for Laravel)

| Role | Permissions |
|---|---|
| `MARKETING_STAFF` (new) | Create leads, log interactions on own leads, view own leads only |
| `BRANCH_HEAD` / cluster head (existing) | View all leads in branch, reassign, convert to subscriber, import leads for any branch |
| `ORG_ADMIN` / `SUPER_ADMIN` (existing) | Full access — all branches, reports, bulk import, delete |
| `SUBSCRIBER` (existing) | No CRM access |

**Import rule**: Only `BRANCH_HEAD` and above can import leads. They can assign to any employee regardless of branch.

---

## 11. Follow-up Queue

- Any lead with a `follow_up_date` appears in the employee's follow-up dashboard.
- **Overdue**: follow-up date < today and `fu_done` is false → shown in red.
- **Due today**: follow-up date = today → shown prominently.
- **Upcoming**: follow-up date within next 7 days.

---

## 12. Reports (Phase 4)

Planned reports:
- Lead funnel by status (new / in_progress / converted / dead)
- Flag distribution (green / yellow / red)
- Branch-wise breakdown (total, converted, conversion rate)
- Employee-wise breakdown
- Follow-up compliance (completed on time vs overdue)
- CSV export matching existing marketing tool format

CSV export columns (matching old tool):
`Lead No, Input Date, Subscriber Name, Mobile No, Visit Place, Status Type, Income Source, Monthly Source, Business Name, Business Place, Interaction Type, Lead Rating, Lead Status, Remainder Date, Remainder Remarks, Responsibility, Created By, Updated Date`

---

## 13. Notifications & Daily Follow-up Reminders

### Approach: Merge CRM into existing Task Bubble

rosca-digital already has a task bubble (`resources/views/layouts/task_bubble.blade.php`) that:
- Shows bottom-right floating widget to the logged-in user
- Polls `/tasks/bubble-summary` every 5 minutes
- Displays pending task count, overdue badge (red pulsing), and a task list
- Auto-opens if there are overdue items

**Decision: extend `bubbleSummary()` to include CRM follow-ups** — no new UI needed.

CRM follow-ups due today or overdue appear in the same bubble alongside operational tasks.
The employee sees "3 pending tasks + 2 CRM follow-ups" in one place.

### `TaskController::bubbleSummary()` — change at Phase 1

File: `app/Http/Controllers/TaskController.php`, method `bubbleSummary()` (line 856)

Replace the final `return response()->json(...)` with:

```php
$today = now()->toDateString();

$crmOverdue = \App\Lead::where('assigned_to', $user->id)
    ->whereNotNull('follow_up_date')
    ->where('follow_up_date', '<', $today)
    ->whereNotIn('status', ['converted', 'dead'])
    ->count();

$crmDue = \App\Lead::where('assigned_to', $user->id)
    ->whereNotNull('follow_up_date')
    ->where('follow_up_date', '<=', $today)
    ->whereNotIn('status', ['converted', 'dead'])
    ->count();

$crmItems = \App\Lead::where('assigned_to', $user->id)
    ->whereNotNull('follow_up_date')
    ->where('follow_up_date', '<=', $today)
    ->whereNotIn('status', ['converted', 'dead'])
    ->orderBy('follow_up_date')
    ->limit(10)
    ->get()
    ->map(fn($l) => [
        'id'           => $l->id,
        'title'        => $l->name,
        'entity_label' => $l->name,
        'process'      => 'CRM Follow-up',
        'stage'        => $l->lead_no . ' · ' . ucfirst($l->flag),
        'status'       => 'InProgress',
        'is_overdue'   => $l->follow_up_date < $today,
        'due_date'     => $l->follow_up_date,
        'url'          => route('leads.show', $l->id),
    ]);

return response()->json([
    'total'   => $totalCount + $crmDue,
    'overdue' => $overdueCount + $crmOverdue,
    'tasks'   => $taskData->concat($crmItems)->sortBy('due_date')->values(),
]);
```

> **Critical dependency**: `leads.assigned_to` must store `user_id` (integer FK to `users` table),
> same as `tasks.assigned_to`. Do NOT store employee name string.

### Daily Morning SMS/Push Notification

New artisan command: `crm:send-followup-reminders`
File: `app/Console/Commands/SendCrmFollowUpReminders.php`

Logic:
1. Run daily at 08:00 via Laravel scheduler (`Console/Kernel.php`)
2. Query all leads where `follow_up_date <= today` and status not in `[converted, dead]`
3. Group by `assigned_to` (user_id)
4. For each employee, send a notification via existing SMS/push channel:
   - Summary: "You have X follow-ups due today and Y overdue. [link]"
   - List of lead names + lead numbers

```php
// Console/Kernel.php
$schedule->command('crm:send-followup-reminders')->dailyAt('08:00');
```

Notification class: `app/Notifications/CrmFollowUpDailyDigest.php`
- Uses existing notification channels (SMS gateway already wired for rosca-digital)
- No new infrastructure needed

### What employees see

| Channel | When | Content |
|---|---|---|
| Task bubble (bottom-right) | Always visible, refreshes every 5 min | Today + overdue follow-ups mixed with operational tasks |
| SMS / push | 08:00 daily | Count of due + overdue leads, link to CRM list |
| Calendar view (CRM) | On demand | Full monthly calendar with clickable dates |
| Follow-up alert banner | CRM Lead List page | Yellow/red strip showing overdue count |

---

## 14. Open Items / To Confirm

- [ ] Should imported leads with an existing `source` column override the default source selection?
  - **Current decision**: Yes, if source column exists and is a recognised value, it overrides.
- [ ] Duplicate handling — should duplicates be offered a "merge/update" option instead of skip?
  - **Current decision**: Skip only (keep it simple for now; merge can come in a later phase).
- [ ] Should cluster heads see all branches' leads or only assigned ones?
  - **Current decision**: TBD — defer to access control design in Phase 1.
- [ ] Should `follow_up_date` in CSV be validated for date format?
  - **Current decision**: Accept as-is; invalid dates will just not show in follow-up queue.
- [ ] GL code or financial category for conversion — needed at lead level or only at enrollment?
  - **Current decision**: Enrollment level only; lead is pre-sales.
- [ ] Should CRM follow-ups also appear in the "My Tasks" page (tasks list view) alongside operational tasks, or only in the bubble?
  - **Current decision**: TBD — bubble is confirmed. My Tasks page integration is optional Phase 2 item.
- [ ] SMS notification content — should it list individual lead names or just a count + link?
  - **Current decision**: TBD — confirm with team before building the notification class.
