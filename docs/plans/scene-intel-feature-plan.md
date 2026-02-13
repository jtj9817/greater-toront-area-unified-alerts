# Scene Intel Feature Implementation Plan

## Executive Summary

**Scene Intel** is a proposed feature to provide real-time operational updates for fire incidents. Currently, the UI displays static mock data ("Hydrant confirmed operational", "Search of Floor 1 complete", "Command established - Pumper 12"). This document outlines a comprehensive implementation plan to evolve Scene Intel into a fully functional system that captures, stores, and displays genuine incident progression data.

**Status:** Planning Phase  
**Scope:** Backend data pipeline, database schema, API extensions, frontend components  
**Primary Data Source:** Toronto Fire Services CAD system (with extensibility for future sources)

---

## Current State Analysis

### Existing Mock Implementation

**Location:** `resources/js/features/gta-alerts/components/AlertDetailsView.tsx`

The current "Scene Intel" UI is hardcoded in the `buildFireSections()` function:

```tsx
<div className="rounded-2xl border border-white/5 bg-surface-dark p-6">
    <h4 className="mb-4 flex items-center gap-2 text-xs font-bold text-primary uppercase">
        <Icon name="list_alt" className="text-sm" /> Scene Intel
    </h4>
    <ul className="space-y-3">
        {[
            'Hydrant confirmed operational',
            'Search of Floor 1 complete',
            'Command established - Pumper 12',
        ].map((intel, i) => (
            <li key={i} className="flex gap-3 text-sm text-gray-400">
                <span className="font-bold text-coral">•</span>
                {intel}
            </li>
        ))}
    </ul>
</div>
```

**Display Conditions:**
- Only appears for fire alerts (`alert.kind === 'fire'`)
- Not shown for medical emergencies (which display a "Medical Advisory" instead)
- Positioned in a 2-column layout alongside a "Location Map" placeholder

### Available Fire Incident Data (Current)

The `fire_incidents` table currently stores:

| Field | Type | Description |
|-------|------|-------------|
| `event_num` | string (unique) | Incident ID (e.g., `F26015952`) |
| `event_type` | string | Incident category |
| `prime_street` | string? | Primary location |
| `cross_streets` | string? | Cross streets |
| `dispatch_time` | datetime | Initial dispatch timestamp |
| `alarm_level` | tinyint | 0-6 scale (response escalation) |
| `beat` | string? | Fire station/district |
| `units_dispatched` | string? | Comma-separated apparatus codes |
| `is_active` | boolean | Whether incident is still open |
| `feed_updated_at` | timestamp | Last CAD feed sync |

**Current Metadata Flow:**
```
FireAlertSelectProvider
  ↓ (JSON_OBJECT)
UnifiedAlert.meta = {
  alarm_level: number,
  event_num: string,
  units_dispatched: string | null,
  beat: string | null
}
  ↓
FireMetaSchema (Zod validation)
  ↓
FireAlert domain type
  ↓
buildFireDescriptionAndMetadata()
  ↓
AlertPresentation.metadata
```

---

## Gap Analysis

### What Scene Intel Requires vs. What's Available

| Required Intel Type | Example | Current Availability |
|---------------------|---------|---------------------|
| **Operational milestones** | "Command established", "Primary search complete" | ❌ Not available |
| **Resource status updates** | "Hydrant confirmed operational", "Aerial deployed" | ❌ Not available |
| **Unit arrival/departure** | "Pumper 12 on scene", "Rescue 31 cleared" | ⚠️ Partial (units_dispatched shows assigned, not status) |
| **Alarm level changes** | "Escalated to 2-Alarm" | ⚠️ Partial (current level only, no history) |
| **Incident phase transitions** | "Under control", "Fire knocked down", "Under investigation" | ❌ Not available |

### Data Source Limitations

The Toronto Fire CAD XML feed (`https://www.toronto.ca/data/fire/livecad.xml`) provides only a **point-in-time snapshot** of active incidents. It does not include:
- Historical progression of an incident
- Individual unit status changes
- Operational milestones or tactical updates
- Incident phase transitions

---

## Proposed Architecture

### Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         SCENE INTEL ARCHITECTURE                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  DATA SOURCES                    PROCESSING LAYER                          │
│  ┌─────────────────┐            ┌──────────────────────────────┐           │
│  │ Toronto Fire CAD│───────────→│ TorontoFireFeedService       │           │
│  │ (XML feed)      │            │ (existing - fetch/parse)     │           │
│  └─────────────────┘            └──────────────────────────────┘           │
│           │                                │                                │
│           │                                ↓                                │
│           │                     ┌──────────────────────────────┐           │
│           │                     │ FetchFireIncidentsCommand    │           │
│           │                     │ (existing - upsert logic)    │           │
│           │                     └──────────────────────────────┘           │
│           │                                │                                │
│           │                    ┌───────────┴───────────┐                    │
│           │                    ↓                       ↓                    │
│           │         ┌──────────────────┐   ┌────────────────────┐          │
│           │         │ fire_incidents   │   │ incident_updates   │          │
│           │         │ (existing)       │   │ (NEW TABLE)        │          │
│           │         └──────────────────┘   └────────────────────┘          │
│           │                    ↑                       ↑                    │
│           │                    └───────────┬───────────┘                    │
│           │                                │                                │
│           │                     ┌──────────────────────────────┐           │
│           │                     │ SceneIntelProcessor          │           │
│           │                     │ (NEW - diff detection)       │           │
│           │                     └──────────────────────────────┘           │
│           │                                ↑                                │
│           │                                │                                │
│  ┌────────┴────────┐            ┌──────────────────────────────┐           │
│  │ Future Sources: │            │ SceneIntelGenerator          │           │
│  │ - TFS Web Portal│            │ (NEW - synthetic intel from  │           │
│  │ - Manual Entry  │            │  unit changes)               │           │
│  │ - Radio Trans.  │            └──────────────────────────────┘           │
│  └─────────────────┘                                                       │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘

                                      ↓

┌─────────────────────────────────────────────────────────────────────────────┐
│                           API LAYER                                         │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ FireAlertSelectProvider (MODIFIED)                                  │   │
│  │ - Join with incident_updates for latest intel                       │   │
│  │ - Include intel_summary in meta JSON                                │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ NEW: SceneIntelController                                           │   │
│  │ GET /api/incidents/{eventNum}/intel - Full intel timeline           │   │
│  │ POST /api/incidents/{eventNum}/intel - Manual intel entry (admin)   │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘

                                      ↓

┌─────────────────────────────────────────────────────────────────────────────┐
│                         FRONTEND LAYER                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ FireMetaSchema (EXTENDED)                                           │   │
│  │ - Add intel_summary: SceneIntelItem[]                               │   │
│  │ - Add intel_last_updated: string                                    │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ AlertDetailsView.tsx (MODIFIED)                                     │   │
│  │ - Replace hardcoded list with dynamic intel data                    │   │
│  │ - Add intel timeline visualization                                  │   │
│  │ - Support real-time updates via polling or WebSocket                │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ NEW: SceneIntelTimeline Component                                   │   │
│  │ - Chronological display of intel items                              │   │
│  │ - Categorized by type (milestone, resource, status)                 │   │
│  │ - Expandable/collapsible sections                                   │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Database Schema

### New Table: `incident_updates`

Stores individual Scene Intel entries for fire incidents.

```sql
CREATE TABLE incident_updates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_num VARCHAR(255) NOT NULL,           -- Links to fire_incidents.event_num
    update_type VARCHAR(50) NOT NULL,          -- Category of update (see below)
    content TEXT NOT NULL,                     -- Human-readable description
    metadata JSON NULL,                        -- Structured data (unit codes, timestamps, etc.)
    source VARCHAR(50) NOT NULL DEFAULT 'synthetic', -- 'synthetic' | 'manual' | 'external_api'
    created_by BIGINT UNSIGNED NULL,           -- User ID for manual entries
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_event_num_created (event_num, created_at),
    INDEX idx_update_type (update_type),
    INDEX idx_created_at (created_at),
    
    -- Foreign key
    FOREIGN KEY (event_num) REFERENCES fire_incidents(event_num) ON DELETE CASCADE
);
```

### Update Types Enum

```php
enum IncidentUpdateType: string
{
    case MILESTONE = 'milestone';           // Command established, Primary search complete
    case RESOURCE_STATUS = 'resource_status'; // Unit arrived, Unit cleared, Hydrant connected
    case ALARM_CHANGE = 'alarm_change';     // Escalated to 2-Alarm, Downgraded
    case PHASE_CHANGE = 'phase_change';     // Under control, Fire knocked down
    case SAFETY_NOTICE = 'safety_notice';   // Evacuation order, Shelter in place
    case WEATHER_ALERT = 'weather_alert';   // Wind conditions affecting operations
    case MANUAL_NOTE = 'manual_note';       // Freeform entry by dispatcher/admin
}
```

### Metadata Schema (JSON)

Flexible structure based on update type:

```typescript
// RESOURCE_STATUS
{
  unitCode: string;           // "P144"
  unitType: string;           // "Pumper"
  status: 'arrived' | 'cleared' | 'deployed' | 'refilling';
  previousStatus?: string;
  timestamp: string;          // ISO 8601
}

// ALARM_CHANGE
{
  previousLevel: number;
  newLevel: number;
  reason?: string;            // "Request for additional apparatus"
}

// MILESTONE
{
  milestoneType: 'command_established' | 'primary_search_complete' | 'secondary_search_complete' | 'ventilation_complete' | 'overhaul_begin';
  completedBy?: string;       // Unit code or "Incident Commander"
}

// PHASE_CHANGE
{
  previousPhase: string;
  newPhase: 'investigating' | 'offensive' | 'defensive' | 'contained' | 'under_control' | 'overhaul' | 'terminated';
}
```

### Migration File

**File:** `database/migrations/2026_02_15_000001_create_incident_updates_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_updates', function (Blueprint $table) {
            $table->id();
            $table->string('event_num');
            $table->string('update_type', 50);
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->string('source', 50)->default('synthetic');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            
            $table->index(['event_num', 'created_at']);
            $table->index('update_type');
            $table->index('created_at');
            
            $table->foreign('event_num')
                ->references('event_num')
                ->on('fire_incidents')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_updates');
    }
};
```

---

## Backend Implementation

### 1. Model: `IncidentUpdate`

**File:** `app/Models/IncidentUpdate.php`

```php
<?php

namespace App\Models;

use App\Enums\IncidentUpdateType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_num',
        'update_type',
        'content',
        'metadata',
        'source',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'update_type' => IncidentUpdateType::class,
            'metadata' => 'array',
            'created_by' => 'integer',
        ];
    }

    public function fireIncident(): BelongsTo
    {
        return $this->belongsTo(FireIncident::class, 'event_num', 'event_num');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForIncident(Builder $query, string $eventNum): void
    {
        $query->where('event_num', $eventNum);
    }

    public function scopeOfType(Builder $query, IncidentUpdateType $type): void
    {
        $query->where('update_type', $type);
    }

    public function scopeRecent(Builder $query, int $limit = 10): void
    {
        $query->orderByDesc('created_at')->limit($limit);
    }
}
```

### 2. Enum: `IncidentUpdateType`

**File:** `app/Enums/IncidentUpdateType.php`

```php
<?php

namespace App\Enums;

enum IncidentUpdateType: string
{
    case MILESTONE = 'milestone';
    case RESOURCE_STATUS = 'resource_status';
    case ALARM_CHANGE = 'alarm_change';
    case PHASE_CHANGE = 'phase_change';
    case SAFETY_NOTICE = 'safety_notice';
    case WEATHER_ALERT = 'weather_alert';
    case MANUAL_NOTE = 'manual_note';

    /**
     * Get human-readable label for the update type.
     */
    public function label(): string
    {
        return match ($this) {
            self::MILESTONE => 'Milestone',
            self::RESOURCE_STATUS => 'Resource Update',
            self::ALARM_CHANGE => 'Alarm Level Change',
            self::PHASE_CHANGE => 'Incident Phase Change',
            self::SAFETY_NOTICE => 'Safety Notice',
            self::WEATHER_ALERT => 'Weather Alert',
            self::MANUAL_NOTE => 'Note',
        };
    }

    /**
     * Get icon name for UI representation.
     */
    public function icon(): string
    {
        return match ($this) {
            self::MILESTONE => 'flag',
            self::RESOURCE_STATUS => 'local_fire_department',
            self::ALARM_CHANGE => 'trending_up',
            self::PHASE_CHANGE => 'sync',
            self::SAFETY_NOTICE => 'warning',
            self::WEATHER_ALERT => 'cloud',
            self::MANUAL_NOTE => 'note',
        };
    }
}
```

### 3. Service: `SceneIntelProcessor`

**File:** `app/Services/SceneIntel/SceneIntelProcessor.php`

Analyzes fire incident changes and generates synthetic intel entries.

```php
<?php

namespace App\Services\SceneIntel;

use App\Enums\IncidentUpdateType;
use App\Models\FireIncident;
use App\Models\IncidentUpdate;
use Illuminate\Support\Facades\Log;

class SceneIntelProcessor
{
    /**
     * Process a fire incident update and generate intel entries.
     * Called from FetchFireIncidentsCommand when an incident is updated.
     */
    public function processIncidentUpdate(FireIncident $incident, array $previousData): void
    {
        // Check for alarm level changes
        if ($previousData['alarm_level'] !== $incident->alarm_level) {
            $this->recordAlarmLevelChange($incident, $previousData['alarm_level']);
        }

        // Check for unit dispatch changes
        if ($previousData['units_dispatched'] !== $incident->units_dispatched) {
            $this->processUnitChanges($incident, $previousData['units_dispatched']);
        }

        // Check for status changes (active/inactive)
        if ($previousData['is_active'] && !$incident->is_active) {
            $this->recordIncidentClosure($incident);
        }
    }

    /**
     * Record an alarm level change.
     */
    private function recordAlarmLevelChange(FireIncident $incident, int $previousLevel): void
    {
        $newLevel = $incident->alarm_level;
        $isEscalation = $newLevel > $previousLevel;
        
        $content = $isEscalation
            ? "Escalated to {$this->alarmLevelToString($newLevel)}"
            : "Downgraded to {$this->alarmLevelToString($newLevel)}";

        IncidentUpdate::create([
            'event_num' => $incident->event_num,
            'update_type' => IncidentUpdateType::ALARM_CHANGE,
            'content' => $content,
            'metadata' => [
                'previousLevel' => $previousLevel,
                'newLevel' => $newLevel,
                'isEscalation' => $isEscalation,
            ],
            'source' => 'synthetic',
        ]);
    }

    /**
     * Process unit dispatch changes.
     */
    private function processUnitChanges(FireIncident $incident, ?string $previousUnits): void
    {
        $currentUnits = $incident->units_dispatched ?? '';
        $previousUnits = $previousUnits ?? '';

        $currentArray = array_filter(array_map('trim', explode(',', $currentUnits)));
        $previousArray = array_filter(array_map('trim', explode(',', $previousUnits)));

        $added = array_diff($currentArray, $previousArray);
        $removed = array_diff($previousArray, $currentArray);

        foreach ($added as $unit) {
            IncidentUpdate::create([
                'event_num' => $incident->event_num,
                'update_type' => IncidentUpdateType::RESOURCE_STATUS,
                'content' => "{$this->formatUnitName($unit)} dispatched",
                'metadata' => [
                    'unitCode' => $unit,
                    'status' => 'dispatched',
                    'timestamp' => now()->toIso8601String(),
                ],
                'source' => 'synthetic',
            ]);
        }

        foreach ($removed as $unit) {
            IncidentUpdate::create([
                'event_num' => $incident->event_num,
                'update_type' => IncidentUpdateType::RESOURCE_STATUS,
                'content' => "{$this->formatUnitName($unit)} cleared",
                'metadata' => [
                    'unitCode' => $unit,
                    'status' => 'cleared',
                    'timestamp' => now()->toIso8601String(),
                ],
                'source' => 'synthetic',
            ]);
        }
    }

    /**
     * Record incident closure.
     */
    private function recordIncidentClosure(FireIncident $incident): void
    {
        IncidentUpdate::create([
            'event_num' => $incident->event_num,
            'update_type' => IncidentUpdateType::PHASE_CHANGE,
            'content' => 'Incident marked as resolved',
            'metadata' => [
                'newPhase' => 'terminated',
                'duration_minutes' => $incident->dispatch_time->diffInMinutes(now()),
            ],
            'source' => 'synthetic',
        ]);
    }

    private function alarmLevelToString(int $level): string
    {
        return match ($level) {
            0 => 'Initial Response',
            1 => '1-Alarm (Support)',
            2 => '2-Alarm (10-14 units)',
            3 => '3-Alarm (15-18 units)',
            4 => '4-Alarm (19-22 units)',
            5 => '5-Alarm (23-28 units)',
            6 => '6-Alarm (29-32 units)',
            default => "{$level}-Alarm",
        };
    }

    private function formatUnitName(string $unit): string
    {
        $type = match (substr($unit, 0, 1)) {
            'P' => 'Pumper',
            'R' => 'Rescue',
            'A' => 'Aerial',
            'T' => 'Tower',
            'S' => 'Squad',
            'HR' => 'Highrise',
            'HZ' => 'HazMat',
            default => 'Unit',
        };

        return "{$type} {$unit}";
    }
}
```

### 4. Service: `SceneIntelRepository`

**File:** `app/Services/SceneIntel/SceneIntelRepository.php`

Query operations for Scene Intel data.

```php
<?php

namespace App\Services\SceneIntel;

use App\Models\IncidentUpdate;
use Illuminate\Database\Eloquent\Collection;

class SceneIntelRepository
{
    /**
     * Get the latest intel items for an incident.
     *
     * @return Collection<int, IncidentUpdate>
     */
    public function getLatestForIncident(string $eventNum, int $limit = 10): Collection
    {
        return IncidentUpdate::forIncident($eventNum)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get intel summary formatted for API response.
     *
     * @return array<int, array{
     *     id: int,
     *     type: string,
     *     type_label: string,
     *     content: string,
     *     icon: string,
     *     timestamp: string,
     *     metadata: array|null
     * }>
     */
    public function getSummaryForIncident(string $eventNum, int $limit = 5): array
    {
        return IncidentUpdate::forIncident($eventNum)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (IncidentUpdate $update) => [
                'id' => $update->id,
                'type' => $update->update_type->value,
                'type_label' => $update->update_type->label(),
                'content' => $update->content,
                'icon' => $update->update_type->icon(),
                'timestamp' => $update->created_at->toIso8601String(),
                'metadata' => $update->metadata,
            ])
            ->toArray();
    }

    /**
     * Get full timeline for an incident.
     */
    public function getTimeline(string $eventNum): Collection
    {
        return IncidentUpdate::forIncident($eventNum)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Add a manual intel entry.
     */
    public function addManualEntry(
        string $eventNum,
        string $content,
        int $userId,
        ?array $metadata = null
    ): IncidentUpdate {
        return IncidentUpdate::create([
            'event_num' => $eventNum,
            'update_type' => \App\Enums\IncidentUpdateType::MANUAL_NOTE,
            'content' => $content,
            'metadata' => $metadata,
            'source' => 'manual',
            'created_by' => $userId,
        ]);
    }
}
```

### 5. Modified: `FetchFireIncidentsCommand`

**File:** `app/Console/Commands/FetchFireIncidentsCommand.php`

Integrate Scene Intel processing into the existing sync command.

```php
// Add to constructor injection
public function __construct(
    private TorontoFireFeedService $feedService,
    private SceneIntelProcessor $intelProcessor,  // NEW
) {
    parent::__construct();
}

// In the handle() method, when updating existing incidents:
foreach ($events as $event) {
    $existing = FireIncident::where('event_num', $event['event_num'])->first();
    
    if ($existing) {
        // Capture previous state before update
        $previousData = [
            'alarm_level' => $existing->alarm_level,
            'units_dispatched' => $existing->units_dispatched,
            'is_active' => $existing->is_active,
        ];
        
        $existing->update([...]);
        
        // Process for intel generation
        $this->intelProcessor->processIncidentUpdate($existing, $previousData);
    } else {
        // New incident - create initial intel entry
        FireIncident::create([...]);
        $this->createInitialIntelEntry($event);
    }
}

private function createInitialIntelEntry(array $event): void
{
    IncidentUpdate::create([
        'event_num' => $event['event_num'],
        'update_type' => IncidentUpdateType::MILESTONE,
        'content' => 'Incident reported - ' . $event['event_type'],
        'metadata' => [
            'milestoneType' => 'incident_opened',
            'initial_units' => $event['units_dispatched'],
        ],
        'source' => 'synthetic',
    ]);
}
```

### 6. Modified: `FireAlertSelectProvider`

**File:** `app/Services/Alerts/Providers/FireAlertSelectProvider.php`

Include Scene Intel summary in the meta field.

```php
// In the select() method, modify the meta expression:
$metaExpression = $isSQLite
    ? "json_object(
        'alarm_level', alarm_level, 
        'units_dispatched', units_dispatched, 
        'beat', beat, 
        'event_num', event_num,
        'intel_summary', (SELECT json_group_array(json_object('type', update_type, 'content', content, 'timestamp', created_at)) 
                          FROM incident_updates 
                          WHERE incident_updates.event_num = fire_incidents.event_num 
                          ORDER BY created_at DESC 
                          LIMIT 5)
    )"
    : "JSON_OBJECT(
        'alarm_level', alarm_level, 
        'units_dispatched', units_dispatched, 
        'beat', beat, 
        'event_num', event_num,
        'intel_summary', (SELECT JSON_ARRAYAGG(JSON_OBJECT('type', update_type, 'content', content, 'timestamp', created_at))
                          FROM (SELECT update_type, content, created_at 
                                FROM incident_updates 
                                WHERE incident_updates.event_num = fire_incidents.event_num 
                                ORDER BY created_at DESC 
                                LIMIT 5) AS recent_intel)
    )";
```

**Note:** The subquery approach may have performance implications. Consider a separate query or caching strategy for high-traffic scenarios.

### 7. New Controller: `SceneIntelController`

**File:** `app/Http/Controllers/SceneIntelController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Services\SceneIntel\SceneIntelRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SceneIntelController extends Controller
{
    public function __construct(
        private SceneIntelRepository $repository
    ) {}

    /**
     * Get full intel timeline for an incident.
     */
    public function timeline(string $eventNum): JsonResponse
    {
        $timeline = $this->repository->getTimeline($eventNum);
        
        return response()->json([
            'event_num' => $eventNum,
            'count' => $timeline->count(),
            'items' => $timeline->map(fn ($item) => [
                'id' => $item->id,
                'type' => $item->update_type->value,
                'type_label' => $item->update_type->label(),
                'icon' => $item->update_type->icon(),
                'content' => $item->content,
                'metadata' => $item->metadata,
                'source' => $item->source,
                'created_at' => $item->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Add a manual intel entry (requires admin/dispatcher role).
     */
    public function store(Request $request, string $eventNum): JsonResponse
    {
        // TODO: Add authorization check for admin/dispatcher role
        
        $validated = $request->validate([
            'content' => 'required|string|max:500',
            'metadata' => 'nullable|array',
        ]);

        $entry = $this->repository->addManualEntry(
            $eventNum,
            $validated['content'],
            Auth::id(),
            $validated['metadata'] ?? null
        );

        return response()->json([
            'id' => $entry->id,
            'content' => $entry->content,
            'created_at' => $entry->created_at->toIso8601String(),
        ], 201);
    }
}
```

### 8. Routes

**File:** `routes/web.php`

```php
use App\Http\Controllers\SceneIntelController;

// Scene Intel API routes
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/api/incidents/{eventNum}/intel', [SceneIntelController::class, 'timeline']);
    Route::post('/api/incidents/{eventNum}/intel', [SceneIntelController::class, 'store'])
        ->middleware('can:create,App\Models\IncidentUpdate'); // Or custom dispatcher role
});
```

---

## Frontend Implementation

### 1. Extended Schema: `FireMetaSchema`

**File:** `resources/js/features/gta-alerts/domain/alerts/fire/schema.ts`

```typescript
import { z } from 'zod/v4';

/**
 * Individual scene intel item from the backend.
 */
export const SceneIntelItemSchema = z.object({
    id: z.number(),
    type: z.enum(['milestone', 'resource_status', 'alarm_change', 'phase_change', 'safety_notice', 'weather_alert', 'manual_note']),
    type_label: z.string(),
    content: z.string(),
    icon: z.string(),
    timestamp: z.string(), // ISO 8601
    metadata: z.record(z.unknown()).nullable(),
});

export type SceneIntelItem = z.infer<typeof SceneIntelItemSchema>;

/**
 * Extended Fire meta schema with Scene Intel support.
 */
export const FireMetaSchema = z.object({
    alarm_level: z.number(),
    event_num: z.string(),
    units_dispatched: z.nullable(z.string()),
    beat: z.nullable(z.string()),
    // NEW: Scene Intel fields
    intel_summary: z.array(SceneIntelItemSchema).default([]),
    intel_last_updated: z.string().nullable(),
});

export type FireMeta = z.infer<typeof FireMetaSchema>;
```

### 2. New Component: `SceneIntelTimeline`

**File:** `resources/js/features/gta-alerts/components/SceneIntelTimeline.tsx`

```tsx
import React from 'react';
import { formatTimestampEST, timeAgo } from '@/lib/utils';
import { Icon } from './Icon';
import type { SceneIntelItem } from '../domain/alerts/fire/schema';

interface SceneIntelTimelineProps {
    items: SceneIntelItem[];
    isLoading?: boolean;
    error?: string | null;
}

const typeColors: Record<SceneIntelItem['type'], string> = {
    milestone: 'text-emerald-400 border-emerald-500/20 bg-emerald-500/10',
    resource_status: 'text-blue-400 border-blue-500/20 bg-blue-500/10',
    alarm_change: 'text-amber-400 border-amber-500/20 bg-amber-500/10',
    phase_change: 'text-purple-400 border-purple-500/20 bg-purple-500/10',
    safety_notice: 'text-red-400 border-red-500/20 bg-red-500/10',
    weather_alert: 'text-cyan-400 border-cyan-500/20 bg-cyan-500/10',
    manual_note: 'text-gray-400 border-gray-500/20 bg-gray-500/10',
};

export const SceneIntelTimeline: React.FC<SceneIntelTimelineProps> = ({
    items,
    isLoading,
    error,
}) => {
    if (isLoading) {
        return (
            <div className="rounded-2xl border border-white/5 bg-surface-dark p-6">
                <div className="flex items-center gap-2 text-text-secondary">
                    <Icon name="sync" className="animate-spin" />
                    <span className="text-sm">Loading scene intel...</span>
                </div>
            </div>
        );
    }

    if (error) {
        return (
            <div className="rounded-2xl border border-red-500/20 bg-red-500/10 p-6">
                <div className="flex items-center gap-2 text-red-400">
                    <Icon name="error" />
                    <span className="text-sm">{error}</span>
                </div>
            </div>
        );
    }

    if (items.length === 0) {
        return (
            <div className="rounded-2xl border border-white/5 bg-surface-dark p-6">
                <h4 className="mb-4 flex items-center gap-2 text-xs font-bold text-primary uppercase">
                    <Icon name="list_alt" className="text-sm" /> Scene Intel
                </h4>
                <p className="text-sm text-text-secondary">
                    No operational updates available yet. Updates will appear as the incident progresses.
                </p>
            </div>
        );
    }

    return (
        <div className="rounded-2xl border border-white/5 bg-surface-dark p-6">
            <h4 className="mb-4 flex items-center gap-2 text-xs font-bold text-primary uppercase">
                <Icon name="list_alt" className="text-sm" /> Scene Intel
                <span className="ml-auto text-[10px] font-normal text-text-secondary">
                    {items.length} update{items.length !== 1 ? 's' : ''}
                </span>
            </h4>
            
            <ul className="space-y-3">
                {items.map((item) => (
                    <li
                        key={item.id}
                        className={`flex gap-3 rounded-lg border p-3 text-sm ${typeColors[item.type]}`}
                    >
                        <Icon name={item.icon} className="mt-0.5 shrink-0" />
                        <div className="flex-1 min-w-0">
                            <p className="font-medium">{item.content}</p>
                            <p className="mt-1 text-xs opacity-70">
                                {item.type_label} • {timeAgo(item.timestamp)}
                            </p>
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
};
```

### 3. Modified: `AlertDetailsView.tsx`

**File:** `resources/js/features/gta-alerts/components/AlertDetailsView.tsx`

Replace the hardcoded Scene Intel section with the dynamic component:

```tsx
import { SceneIntelTimeline } from './SceneIntelTimeline';

// In buildFireSections(), replace the specializedContent section:

specializedContent: (
    <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
        <div className="rounded-2xl border border-white/5 bg-surface-dark p-6">
            <h4 className="mb-4 flex items-center gap-2 text-xs font-bold text-primary uppercase">
                <Icon name="map" className="text-sm" /> Location Map
            </h4>
            <div className="relative flex aspect-video items-center justify-center overflow-hidden rounded-lg border border-dashed border-white/10 bg-white/5">
                <div className="absolute inset-0 bg-[radial-gradient(#e0556033_1px,transparent_1px)] [background-size:16px_16px] opacity-20"></div>
                <Icon
                    name="location_on"
                    className="animate-bounce text-4xl text-primary"
                />
                <span className="absolute bottom-4 text-xs text-text-secondary">
                    Interactive Map Loading...
                </span>
            </div>
        </div>
        
        {/* NEW: Dynamic Scene Intel */}
        <SceneIntelTimeline 
            items={alert.meta?.intel_summary || []}
            isLoading={false}
        />
    </div>
),
```

### 4. Service: `SceneIntelService`

**File:** `resources/js/features/gta-alerts/services/SceneIntelService.ts`

```typescript
import { api } from '@/lib/api';
import type { SceneIntelItem } from '../domain/alerts/fire/schema';

export interface SceneIntelTimelineResponse {
    event_num: string;
    count: number;
    items: SceneIntelItem[];
}

export class SceneIntelService {
    /**
     * Fetch full intel timeline for an incident.
     */
    async getTimeline(eventNum: string): Promise<SceneIntelTimelineResponse> {
        const response = await api.get(`/api/incidents/${eventNum}/intel`);
        return response.data;
    }

    /**
     * Add a manual intel entry (requires admin/dispatcher permissions).
     */
    async addManualEntry(
        eventNum: string,
        content: string,
        metadata?: Record<string, unknown>
    ): Promise<{ id: number; content: string; created_at: string }> {
        const response = await api.post(`/api/incidents/${eventNum}/intel`, {
            content,
            metadata,
        });
        return response.data;
    }
}

export const sceneIntelService = new SceneIntelService();
```

### 5. Hook: `useSceneIntel`

**File:** `resources/js/features/gta-alerts/hooks/useSceneIntel.ts`

```typescript
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { sceneIntelService } from '../services/SceneIntelService';

const INTEL_QUERY_KEY = 'scene-intel';

export function useSceneIntel(eventNum: string | null) {
    const queryClient = useQueryClient();

    const timelineQuery = useQuery({
        queryKey: [INTEL_QUERY_KEY, eventNum],
        queryFn: () => eventNum ? sceneIntelService.getTimeline(eventNum) : null,
        enabled: !!eventNum,
        refetchInterval: 30000, // Refetch every 30 seconds for active incidents
    });

    const addEntryMutation = useMutation({
        mutationFn: ({ content, metadata }: { content: string; metadata?: Record<string, unknown> }) => {
            if (!eventNum) throw new Error('Event number required');
            return sceneIntelService.addManualEntry(eventNum, content, metadata);
        },
        onSuccess: () => {
            // Invalidate and refetch timeline
            queryClient.invalidateQueries({ queryKey: [INTEL_QUERY_KEY, eventNum] });
        },
    });

    return {
        timeline: timelineQuery.data,
        isLoading: timelineQuery.isLoading,
        error: timelineQuery.error,
        addEntry: addEntryMutation.mutate,
        isAddingEntry: addEntryMutation.isPending,
    };
}
```

---

## Testing Strategy

### Backend Tests

**File:** `tests/Feature/SceneIntel/SceneIntelProcessorTest.php`

```php
<?php

uses()->group('scene-intel');

beforeEach(function () {
    $this->processor = app(SceneIntelProcessor::class);
});

it('creates alarm level change intel when alarm escalates', function () {
    $incident = FireIncident::factory()->create(['alarm_level' => 1]);
    
    $previousData = ['alarm_level' => 0, 'units_dispatched' => null, 'is_active' => true];
    $incident->alarm_level = 2;
    
    $this->processor->processIncidentUpdate($incident, $previousData);
    
    expect(IncidentUpdate::count())->toBe(1);
    expect(IncidentUpdate::first()->update_type)->toBe(IncidentUpdateType::ALARM_CHANGE);
    expect(IncidentUpdate::first()->content)->toContain('Escalated');
});

it('creates resource status intel when units are added', function () {
    $incident = FireIncident::factory()->create([
        'units_dispatched' => 'P144',
    ]);
    
    $previousData = ['alarm_level' => 0, 'units_dispatched' => null, 'is_active' => true];
    $incident->units_dispatched = 'P144, R31';
    
    $this->processor->processIncidentUpdate($incident, $previousData);
    
    expect(IncidentUpdate::count())->toBe(1);
    expect(IncidentUpdate::first()->update_type)->toBe(IncidentUpdateType::RESOURCE_STATUS);
    expect(IncidentUpdate::first()->content)->toContain('R31');
});

it('creates phase change intel when incident is resolved', function () {
    $incident = FireIncident::factory()->create(['is_active' => false]);
    
    $previousData = ['alarm_level' => 0, 'units_dispatched' => null, 'is_active' => true];
    
    $this->processor->processIncidentUpdate($incident, $previousData);
    
    expect(IncidentUpdate::count())->toBe(1);
    expect(IncidentUpdate::first()->update_type)->toBe(IncidentUpdateType::PHASE_CHANGE);
});
```

**File:** `tests/Feature/SceneIntel/SceneIntelApiTest.php`

```php
<?php

uses()->group('scene-intel');

it('returns intel timeline for an incident', function () {
    $incident = FireIncident::factory()->create();
    IncidentUpdate::factory()->count(3)->create(['event_num' => $incident->event_num]);
    
    actingAs(User::factory()->create())
        ->get("/api/incidents/{$incident->event_num}/intel")
        ->assertOk()
        ->assertJsonCount(3, 'items');
});

it('allows authorized users to create manual entries', function () {
    $incident = FireIncident::factory()->create();
    $user = User::factory()->create();
    // TODO: Assign dispatcher role to user
    
    actingAs($user)
        ->post("/api/incidents/{$incident->event_num}/intel", [
            'content' => 'Test manual entry',
        ])
        ->assertCreated();
    
    expect(IncidentUpdate::count())->toBe(1);
    expect(IncidentUpdate::first()->source)->toBe('manual');
});
```

### Frontend Tests

**File:** `resources/js/features/gta-alerts/components/SceneIntelTimeline.test.tsx`

```tsx
import { render, screen } from '@testing-library/react';
import { SceneIntelTimeline } from './SceneIntelTimeline';
import { describe, it, expect } from 'vitest';

describe('SceneIntelTimeline', () => {
    const mockItems = [
        {
            id: 1,
            type: 'milestone' as const,
            type_label: 'Milestone',
            content: 'Command established',
            icon: 'flag',
            timestamp: new Date().toISOString(),
            metadata: null,
        },
        {
            id: 2,
            type: 'resource_status' as const,
            type_label: 'Resource Update',
            content: 'Pumper 144 on scene',
            icon: 'local_fire_department',
            timestamp: new Date().toISOString(),
            metadata: { unitCode: 'P144' },
        },
    ];

    it('renders loading state', () => {
        render(<SceneIntelTimeline items={[]} isLoading />);
        expect(screen.getByText('Loading scene intel...')).toBeInTheDocument();
    });

    it('renders empty state when no items', () => {
        render(<SceneIntelTimeline items={[]} />);
        expect(screen.getByText('No operational updates available yet')).toBeInTheDocument();
    });

    it('renders intel items with correct styling', () => {
        render(<SceneIntelTimeline items={mockItems} />);
        expect(screen.getByText('Command established')).toBeInTheDocument();
        expect(screen.getByText('Pumper 144 on scene')).toBeInTheDocument();
        expect(screen.getByText('2 updates')).toBeInTheDocument();
    });
});
```

---

## Impact Analysis

### Files to Modify

#### Backend

| File | Change Type | Description |
|------|-------------|-------------|
| `app/Console/Commands/FetchFireIncidentsCommand.php` | Modify | Integrate SceneIntelProcessor for diff detection |
| `app/Services/Alerts/Providers/FireAlertSelectProvider.php` | Modify | Include intel_summary in meta JSON |
| `app/Providers/AppServiceProvider.php` | Modify | Register SceneIntelProcessor as singleton |
| `routes/web.php` | Modify | Add Scene Intel API routes |
| `app/Models/FireIncident.php` | Modify | Add hasMany relationship to IncidentUpdate |

#### New Backend Files

| File | Purpose |
|------|---------|
| `database/migrations/2026_02_15_000001_create_incident_updates_table.php` | Create incident_updates table |
| `app/Models/IncidentUpdate.php` | Eloquent model for intel entries |
| `app/Enums/IncidentUpdateType.php` | Update type enum with labels/icons |
| `app/Services/SceneIntel/SceneIntelProcessor.php` | Generate synthetic intel from incident changes |
| `app/Services/SceneIntel/SceneIntelRepository.php` | Query operations for intel data |
| `app/Http/Controllers/SceneIntelController.php` | API endpoints for intel timeline |
| `database/factories/IncidentUpdateFactory.php` | Test factory |

#### Frontend

| File | Change Type | Description |
|------|-------------|-------------|
| `resources/js/features/gta-alerts/domain/alerts/fire/schema.ts` | Modify | Add SceneIntelItemSchema and extend FireMetaSchema |
| `resources/js/features/gta-alerts/components/AlertDetailsView.tsx` | Modify | Replace hardcoded Scene Intel with dynamic component |

#### New Frontend Files

| File | Purpose |
|------|---------|
| `resources/js/features/gta-alerts/components/SceneIntelTimeline.tsx` | Display intel items with type-based styling |
| `resources/js/features/gta-alerts/services/SceneIntelService.ts` | API client for Scene Intel endpoints |
| `resources/js/features/gta-alerts/hooks/useSceneIntel.ts` | React Query hook for intel data |
| `resources/js/features/gta-alerts/components/SceneIntelTimeline.test.tsx` | Component tests |

### Performance Considerations

1. **Database Queries:** The subquery in `FireAlertSelectProvider` to fetch intel_summary may impact performance at scale. Consider:
   - Adding a materialized view for intel summaries
   - Caching intel summaries in Redis
   - Lazy-loading intel via separate API call instead of including in unified query

2. **Polling Frequency:** The 30-second refetch interval in `useSceneIntel` should be adjusted based on incident activity level:
   - Active incidents: 30 seconds
   - Recently resolved: 2 minutes
   - Historical: No polling

3. **Data Retention:** Implement pruning for old incident_updates (see Maintenance section).

### Security Considerations

1. **Authorization:** Manual intel entry should require a dispatcher or admin role. Implement proper authorization checks in `SceneIntelController::store()`.

2. **Content Validation:** Sanitize manual entry content to prevent XSS. The current 500-character limit helps mitigate abuse.

3. **Audit Trail:** The `source` and `created_by` fields provide an audit trail for intel entries.

---

## Future Enhancements

### Phase 2: Real-time Updates

- Implement WebSocket broadcasting for new intel entries
- Use Laravel Echo + Pusher/Soketi for live updates
- Show toast notifications for significant milestones

### Phase 3: External Data Sources

- Integrate with Toronto Fire's web portal (if API becomes available)
- Parse radio transmission logs (if accessible)
- Connect to mutual aid systems for multi-jurisdiction incidents

### Phase 4: Advanced Analytics

- Incident duration predictions based on alarm level and unit count
- Response time analysis by beat/station
- Heat maps of incident types and frequencies

### Phase 5: Mobile App Integration

- Push notifications for subscribed incidents
- Photo/video upload for manual intel (fire ground footage)
- Offline mode for incident review

---

## Maintenance

### Data Pruning

Add to `docs/backend/maintenance.md`:

```markdown
### Scene Intel Pruning

Incident updates older than 90 days are automatically pruned.

Manual pruning:
```bash
php artisan scene-intel:prune --days=90
```

Scheduled pruning (add to `routes/console.php`):
```php
Schedule::command('scene-intel:prune --days=90')->daily();
```
```

### Monitoring

Key metrics to track:
- Average intel entries per incident
- Time from incident open to first intel entry
- Manual vs. synthetic entry ratio
- API response times for timeline endpoint

---

## Open Questions

1. **Data Source Expansion:** Should we pursue direct integration with Toronto Fire's internal systems, or focus on synthetic intel generation?

2. **Manual Entry Permissions:** Who should have permission to add manual intel entries? Dispatchers only? Incident commanders? Verified first responders?

3. **Intel Accuracy:** How do we handle corrections to intel entries? Soft deletes? Edit history?

4. **Multi-Jurisdiction:** How should Scene Intel work for incidents involving mutual aid from other municipalities?

5. **Public vs. Private Intel:** Should some intel entries be restricted (e.g., tactical details) while others are public?

---

## Appendix A: Unit Code Reference

| Code Prefix | Unit Type | Example |
|-------------|-----------|---------|
| P | Pumper | P144 |
| R | Rescue | R31 |
| A | Aerial | A312 |
| T | Tower | T114 |
| PL | Platform | PL2 |
| S | Squad | S143 |
| MP | Mini Pumper | MP11 |
| HR | Highrise | HR12 |
| HZ | HazMat | HZ1 |
| FB | Fireboat | FB1 |
| CMD | Command Vehicle | CMD1 |
| C | Chief | C4 |
| LA | Air Light | LA1 |
| WT | Water Tanker | WT1 |
| DE | Decon | DE1 |
| HS | Haz Support | HS1 |
| FI | Fire Investigator | FI1 |
| TRS | Trench Rescue Support | TRS1 |

---

## Appendix B: Incident Phase Definitions

| Phase | Description |
|-------|-------------|
| Investigating | Initial assessment, size-up in progress |
| Offensive | Active fire attack, interior operations |
| Defensive | Exterior attack only, structure may be lost |
| Contained | Fire spread stopped, but not extinguished |
| Under Control | Fire extinguished, no immediate threat |
| Overhaul | Checking for extensions, salvage operations |
| Terminated | All units cleared, incident closed |

---

*Document Version: 1.0*  
*Last Updated: 2026-02-13*  
*Author: AI Assistant*  
*Reviewers: Pending*
