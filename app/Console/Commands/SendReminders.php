<?php

namespace App\Console\Commands;

use App\Mail\ReminderDigest;
use App\Models\DocumentControl;
use App\Models\RegisterItem;
use App\Models\User;
use App\Support\Registers;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

#[Signature('reminders:send {--incidents : Only send urgent incident deadline alerts}')]
#[Description('Email owners about due work and approaching incident deadlines')]
class SendReminders extends Command
{
    private const INCIDENT_ALERT_HOURS = 6;

    public function handle(): int
    {
        $byUser = [];
        $this->collectIncidentAlerts($byUser);
        $this->deliver($byUser, 'Incident reporting deadline approaching');

        if (! $this->option('incidents')) {
            $digest = [];
            $this->collectDigest($digest);
            $this->deliver($digest, 'Items needing your attention');
        }

        return self::SUCCESS;
    }

    /** Each deadline alerts once; admins are told too in case the owner is unavailable. */
    private function collectIncidentAlerts(array &$byUser): void
    {
        $admins = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->whereNull('deactivated_at')->pluck('id');

        foreach (RegisterItem::where('type', 'incident')->get()->filter->isOpen() as $item) {
            foreach (Registers::incidentDeadlines($item) as $stage) {
                if ($stage['sent'] || ! $stage['due'] || Carbon::parse($stage['due'])->gt(now()->addHours(self::INCIDENT_ALERT_HOURS))) {
                    continue;
                }
                if (! Cache::add("incident-alert:{$item->id}:{$stage['key']}", true, now()->addDays(40))) {
                    continue;
                }

                $when = $stage['late'] ? 'was due' : 'is due';
                foreach ($admins->concat([$item->owner_id])->filter()->unique() as $userId) {
                    $byUser[$userId][] = "{$item->reference} {$item->title}: {$stage['label']} {$when} " . Carbon::parse($stage['due'])->toDayDateTimeString();
                }
            }
        }
    }

    private function collectDigest(array &$byUser): void
    {
        $soon = today()->addDays(14);

        foreach (RegisterItem::whereNotNull('owner_id')->whereNotNull('due_at')->whereDate('due_at', '<=', $soon)->get()->filter->isOpen() as $item) {
            $byUser[$item->owner_id][] = "{$item->reference} {$item->title}: due " . $item->due_at->toDateString();
        }

        $controls = DocumentControl::with('document:id,title')->whereNotNull('next_review_at')->whereDate('next_review_at', '<=', $soon)->whereHas('document')->get();
        foreach ($controls as $control) {
            if ($control->owner_id) {
                $byUser[$control->owner_id][] = "{$control->doc_number} {$control->document->title}: review due " . $control->next_review_at->toDateString();
            }
        }

        foreach (DocumentControl::with('document:id,title')->where('status', 'in_review')->whereNotNull('approver_id')->whereHas('document')->get() as $control) {
            $byUser[$control->approver_id][] = "{$control->doc_number} {$control->document->title}: waiting for your approval";
        }
    }

    private function deliver(array $byUser, string $heading): void
    {
        foreach (User::whereIn('id', array_keys($byUser))->whereNull('deactivated_at')->get() as $user) {
            try {
                Mail::to($user->email)->send(new ReminderDigest($heading, $byUser[$user->id]));
            } catch (\Throwable $e) {
                report($e);
                $this->warn("Could not email user {$user->id}.");
            }
        }
    }
}
