# Super Organics

## First Steps

This repository contains a small Laravel API that implements the requested feature from Part 3 of the assessment: adding notes to a lead.

To run it:

```bash
docker compose up -d
```

Quick verification:

```bash
curl http://localhost:8000/up
```

The application is available at `http://localhost:8000`.

For a reviewer, the main endpoint to test is:

```http
POST http://localhost:8000/api/leads/1/notes
Content-Type: application/json
```

Request body:

```json
{
  "note": "Buyer wants to review wholesale pricing."
}
```

Example response:

```json
{
  "data": {
    "id": 2,
    "lead_id": 1,
    "user_id": 1,
    "note": "Buyer wants to review wholesale pricing.",
    "created_at": "2026-03-18T00:35:19.000000Z",
    "updated_at": "2026-03-18T00:35:19.000000Z"
  }
}
```

Seeded demo data:

- User: `reviewer@example.com`
- Lead 1: `Green Earth Market`
- Lead 2: `Organic Wholesale Co.`
- One starter note on lead 1

## What This Repo Delivers

This submission covers two things:

- Part A: the implemented API feature from Part 3 of the assessment
- Part B: the written answers for Parts 1, 2, and 4

The implementation is intentionally narrow. I built only what was required to show the requested Laravel fundamentals clearly: routing, model relationships, migration design, validation, resource responses, seeding, and Docker runnability.

## Part A - Feature Implementation

### What was implemented

Required endpoint:

```http
POST /api/leads/{lead}/notes
```

Included pieces:

- `Lead` model
- `LeadNote` model
- `leads` migration
- `lead_notes` migration
- `LeadNoteController@store`
- `StoreLeadNoteRequest`
- `LeadNoteResource`
- route model binding
- seeded demo data
- Docker runtime
- feature tests for success and validation failure

### How it works

The endpoint receives a note payload, resolves the `Lead` through route model binding, validates the request, creates the note through the lead relationship, associates the note to the authenticated user, and returns the created note with a `201 Created` response.

Because the assessment explicitly says authentication does not need to be built, I used a small demo-only middleware that authenticates the first seeded user for the request. This keeps the endpoint runnable in Postman while still satisfying the requirement to associate the note with a logged-in user.

## Part B - Written Answers

## Part 1 — Debug & Review

### 1. What performance problem exists in this code?

This is a classic **N+1 query problem**. The code executes one query to fetch all leads, then executes **one additional query per lead** to fetch its assigned user. If the table has 10,000 leads, this results in 10,001 database queries for a single API call.

Beyond the N+1 issue, there are secondary concerns:

- `DB::table()` returns raw `stdClass` objects — no Eloquent model benefits like casting, relationships, or hidden attributes.
- There is **no pagination**, so the endpoint dumps the entire table into memory and across the wire.
- There is **no select scope** — every column is fetched even if the consumer only needs a subset.

### 2. How would you fix it in Laravel?

The idiomatic Laravel fix applies three layers:

#### a) Use Eloquent with eager loading to eliminate N+1

```php
// Lead model
class Lead extends Model
{
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}

// Controller -- 2 queries total regardless of row count
$leads = Lead::with('assignedUser')->paginate(50);
return LeadResource::collection($leads);
```

#### b) Paginate the results

Using `->paginate()` or `->cursorPaginate()` limits memory usage and network payload. Cursor pagination is preferable at scale since it avoids the `COUNT(*)` overhead of offset pagination.

#### c) Use API Resources for a clean contract

A `LeadResource` (and nested `UserResource`) controls exactly which fields are exposed, preventing accidental data leaks (e.g., user passwords or internal columns) and decoupling the API shape from the database schema.

### 3. If the table grows to 1M leads, what database changes would you consider?

#### Indexing

Ensure a composite or single-column index exists on `assigned_user_id` (for the JOIN/eager load), and on any column used for filtering or sorting (e.g., `status`, `created_at`). For the `lead_notes` table introduced later, a composite index on `(lead_id, created_at DESC)` keeps note lookups fast.

#### Pagination strategy

Switch from offset pagination to **cursor pagination** (keyset). At 1M rows, `OFFSET 900000` forces the database to scan and discard 900k rows, while cursor pagination seeks directly via an indexed `WHERE` clause.

#### Read replicas & caching

Route read-heavy endpoints (like listing leads) to a **read replica**. For frequently hit queries with tolerance for slight staleness, use a short-lived **Redis/cache layer** (e.g., `Cache::remember` with a 60s TTL).

#### Selective column loading

Use `->select()` to fetch only the columns the API consumer needs. At 1M rows, skipping large text columns from the `SELECT` can cut query I/O significantly.

#### Table partitioning (conditional)

If the workload is heavily time-based (e.g., recent leads are queried 100x more than old ones), range partitioning by `created_at` can help. But this adds complexity — only worth it when simpler approaches plateau.


## Part 2 — Data Modeling

### Schema Design

| Column       | Type            | Constraints                  |
|-------------|-----------------|------------------------------|
| id          | BIGINT UNSIGNED | PK, AUTO_INCREMENT           |
| lead_id     | BIGINT UNSIGNED | FK → leads.id, NOT NULL      |
| user_id     | BIGINT UNSIGNED | FK → users.id, NOT NULL      |
| note        | TEXT            | NOT NULL                     |
| created_at  | TIMESTAMP       | NOT NULL, DEFAULT NOW()      |
| updated_at  | TIMESTAMP       | NOT NULL, DEFAULT NOW()      |

### Indexes

Two indexes are essential on this table:

- **lead_id** — Speeds up "get all notes for this lead" queries. The FK constraint creates this implicitly in MySQL/InnoDB, but it should be explicitly declared for clarity.
- **(lead_id, created_at DESC)** — Composite index that directly serves the "latest note per lead" query pattern without requiring a filesort. This is the most important one at scale.

### Laravel Migration

```php
Schema::create('lead_notes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('lead_id')
          ->constrained()
          ->cascadeOnDelete();
    $table->foreignId('user_id')
          ->constrained()
          ->cascadeOnDelete();
    $table->text('note');
    $table->timestamps();

    // Composite index for "latest note per lead" queries
    $table->index(['lead_id', 'created_at']);
});
```

### Querying the Latest Note per Lead Efficiently

This is a well-known SQL challenge ("greatest-N-per-group"). Below are two approaches ranked by practicality.

#### Approach A — latestOfMany relationship (preferred)

In Laravel, the cleanest way is a `latestOfMany` relationship (Laravel 8.42+):

```php
// Lead model
public function latestNote(): HasOne
{
    return $this->hasOne(LeadNote::class)->latestOfMany();
}

// Usage -- 2 queries total via eager loading
$leads = Lead::with('latestNote')->cursorPaginate(50);
```

Under the hood, `latestOfMany()` generates an efficient subquery join. Combined with the composite index on `(lead_id, created_at)`, this performs well even at millions of notes.

#### Approach B — Denormalization (for extreme read performance)

Add a `latest_note` text column (or `latest_note_id` FK) directly on the leads table, updated via a model observer or database trigger whenever a new note is created. This trades write-time overhead for O(1) reads with zero joins. Only justified when reads vastly outnumber writes and the `latestOfMany` approach becomes a bottleneck.

## Part 4 — Engineering Thinking

### If this CRM grows to 50 sales reps and 500k leads, what would you improve next?

#### 1. Query & database layer

- Enforce **cursor pagination** on all list endpoints. Offset pagination degrades at high row counts.
- Add targeted indexes based on real query patterns (`EXPLAIN ANALYZE` on slow queries). Common candidates: `(status, created_at)`, `(assigned_user_id, status)`.
- Enable **read replica routing** via Laravel's database config for all GET endpoints.

#### 2. API design

- Introduce **filtering and sparse fieldsets**: let consumers request only leads matching certain statuses, date ranges, or assigned reps — reducing data transfer and query scope.
- Apply **rate limiting** (throttle middleware) to protect the API under concurrent rep usage.
- Use **API Resources consistently** to version the contract and prevent schema leaks.

#### 3. Background processing

- Move expensive operations (CSV exports, bulk assignments, email notifications) to **queued jobs** (Laravel Horizon + Redis) so request latency stays low.
- Implement **webhooks or events** for lead changes so downstream systems react asynchronously instead of being polled.

#### 4. Observability

- Structured logging + APM (e.g., Laravel Telescope in dev, Datadog/New Relic in production) to catch slow queries and endpoint degradation before users report it.
- Set up **alerting on P95 latency** for critical endpoints.

#### 5. Authorization

- At 50 reps, role-based access becomes critical. Implement **Laravel Policies** so reps only see their own leads while managers get a cross-team view.
- This also protects the notes endpoint — reps shouldn't add notes to leads they don't own.


### Where could AI automation help this CRM?

#### 1. Lead scoring & prioritization

An ML model (or even a well-tuned heuristic using an LLM) can analyze lead metadata, note history, and engagement patterns to rank leads by conversion probability. This helps reps focus on high-value prospects instead of working the list linearly.

#### 2. Note summarization & intelligence

After months of notes, a lead's history becomes hard to scan. An LLM can generate a one-paragraph summary of all notes for a lead on demand, or extract structured data (next action, buyer sentiment, requested products) from unstructured notes.

#### 3. Automated follow-up suggestions

Based on note content and elapsed time, AI can suggest (or draft) follow-up emails/messages. For example: "This lead requested a pricing sheet 5 days ago and hasn't been contacted since — here is a suggested follow-up."
