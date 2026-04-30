<?php

// ─── ChatV2DemoSeeder ────────────────────────────────────────────────────────
// Seeds two specialized (role-group) channels and one group DM so a fresh
// `migrate:fresh --seed` shows the new Chat v2 surfaces with realistic data.
//
//   "RN Huddle"         : targets JobTitle 'rn' across primary_care + home_care
//   "All Clinical Leads": site-wide, targets job titles 'rn', 'lpn', 'md'
//   "Project Sunrise"   : a 4-member group DM (mixed depts)
//
// Idempotent : no-ops if channels with the same name already exist.
//
// Depends on : DemoEnvironmentSeeder (must have run already so users + a tenant
// exist). The DemoEnvironmentSeeder calls this near the bottom of its run().
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Models\JobTitle;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ChatV2DemoSeeder extends Seeder
{
    public function run(ChatService $service): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->first() ?? Tenant::first();
        if (! $tenant) {
            $this->command?->warn('  No tenant, skipping chat v2 demo channels.');
            return;
        }

        // Make sure a 'rn' / 'lpn' / 'md' JobTitle exists in this tenant so the
        // role-group targeting + @rn parsing have something to point at.
        $titles = ['rn' => 'Registered Nurse', 'lpn' => 'Licensed Practical Nurse', 'md' => 'Physician'];
        $sortOrder = 1;
        foreach ($titles as $code => $label) {
            JobTitle::firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                ['label' => $label, 'is_active' => true, 'sort_order' => $sortOrder++],
            );
        }

        // Assign demo job titles to a slice of users so the role-group has actual members.
        // Pick 8 primary_care + 4 home_care users and tag them as RN.
        User::where('tenant_id', $tenant->id)
            ->where('department', 'primary_care')
            ->where('is_active', true)
            ->whereNull('job_title')
            ->limit(8)
            ->update(['job_title' => 'rn']);

        User::where('tenant_id', $tenant->id)
            ->where('department', 'home_care')
            ->where('is_active', true)
            ->whereNull('job_title')
            ->limit(4)
            ->update(['job_title' => 'rn']);

        $creator = User::where('tenant_id', $tenant->id)
            ->where('role', 'admin')
            ->where('department', 'primary_care')
            ->first()
            ?? User::where('tenant_id', $tenant->id)->where('role', 'super_admin')->first();

        if (! $creator) {
            $this->command?->warn('  No admin user in tenant for chat v2 seeder ; skipping.');
            return;
        }

        // ── 1) Multi-dept role-group ───────────────────────────────────────────
        $rnHuddle = ChatChannel::where('tenant_id', $tenant->id)
            ->where('channel_type', 'role_group')
            ->where('name', 'RN Huddle')
            ->first();
        if (! $rnHuddle) {
            $rnHuddle = $service->createRoleGroupChannel(
                $tenant->id, $creator,
                'RN Huddle',
                'Daily huddle for primary care + home care RNs.',
                ['rn'],
                ['primary_care', 'home_care'],
                false,
            );
            $this->seedSampleMessages($rnHuddle, $creator, [
                'Morning team. Two new admits from yesterday.',
                'I will round on the East side this morning.',
                'Pinning the new fall protocol below : @rn please review.',
            ]);
        }

        // ── 2) Site-wide role-group (executive-created) ───────────────────────
        $exec = User::where('tenant_id', $tenant->id)
            ->where('department', 'executive')
            ->first();
        $clinicalLeads = ChatChannel::where('tenant_id', $tenant->id)
            ->where('channel_type', 'role_group')
            ->where('name', 'All Clinical Leads')
            ->first();
        if (! $clinicalLeads && $exec) {
            $clinicalLeads = $service->createRoleGroupChannel(
                $tenant->id, $exec,
                'All Clinical Leads',
                'Site-wide channel for RNs, LPNs, and MDs.',
                ['rn', 'lpn', 'md'],
                [],
                true,
            );
            $this->seedSampleMessages($clinicalLeads, $exec, [
                'Welcome to the site-wide clinical leads channel.',
                'Reminder : Q2 quality review cycle starts Monday.',
            ]);
        }

        // ── 3) Group DM (4-member multi-dept) ─────────────────────────────────
        $projectMembers = User::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->whereIn('department', ['primary_care', 'social_work', 'pharmacy', 'finance'])
            ->limit(4)
            ->get();

        $existingGroupDm = ChatChannel::where('tenant_id', $tenant->id)
            ->where('channel_type', 'group_dm')
            ->where('name', 'Project Sunrise')
            ->first();
        if (! $existingGroupDm && $projectMembers->count() >= 3) {
            $service->createGroupDmChannel(
                $tenant->id,
                $projectMembers->first(),
                'Project Sunrise',
                $projectMembers->pluck('id')->all(),
            );
        }

        $this->command?->line('    Chat v2 demo : <comment>RN Huddle, All Clinical Leads, Project Sunrise</comment>');
    }

    /**
     * Seed a few sample messages so the channel isn't empty when a tester
     * opens it. Sender is rotated through real members for realism.
     *
     * @param  string[]  $texts
     */
    private function seedSampleMessages(ChatChannel $channel, User $primarySender, array $texts): void
    {
        $members = $channel->memberships()->pluck('user_id')->all();
        if (empty($members)) {
            return;
        }
        foreach ($texts as $i => $text) {
            // Rotate senders to make the thread feel multi-voice.
            $senderId = $members[$i % count($members)] ?? $primarySender->id;
            ChatMessage::create([
                'channel_id'     => $channel->id,
                'sender_user_id' => $senderId,
                'message_text'   => $text,
                'priority'       => 'standard',
                'sent_at'        => Carbon::now()->subMinutes(60 - $i * 5),
            ]);
        }
    }
}
