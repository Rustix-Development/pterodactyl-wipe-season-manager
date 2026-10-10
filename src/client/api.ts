import { queryOptions } from '@tanstack/react-query';
import { http } from '@pterodactyl/sdk';

export type Repeat = 'once' | 'daily' | 'weekly' | 'biweekly' | 'monthly' | 'first-thursday';
export type SeedMode = 'keep' | 'random' | 'list';
export type RunStatus =
    | 'warning'
    | 'stopping'
    | 'backing_up'
    | 'wiping'
    | 'starting'
    | 'done'
    | 'failed'
    | 'cancelled'
    | 'skipped'
    | 'missed';

export interface PlanInput {
    name: string;
    game: string;
    enabled: boolean;
    anchor_local: string;
    timezone: string;
    repeat: Repeat;
    skip_within_hours: number | null;
    warn_minutes: number[];
    warn_command: string | null;
    backup: boolean;
    paths: string[];
    seed_variable: string | null;
    seed_mode: SeedMode;
    seed_list: string[];
    map_variable: string | null;
    map_list: string[];
    /** Null keeps the saved webhook, an empty string removes it. */
    discord_webhook: string | null;
    discord_message: string | null;
}

export interface Plan extends Omit<PlanInput, 'discord_webhook'> {
    id: number;
    skipping_next: boolean;
    next_run_at: string | null;
    last_run_at: string | null;
    /** The end of the saved webhook, or null when none is set. */
    discord_webhook: string | null;
}

export interface Run {
    id: number;
    plan_id: number | null;
    plan_name: string;
    trigger: 'schedule' | 'manual';
    status: RunStatus;
    scheduled_for: string;
    started_at: string | null;
    finished_at: string | null;
    files_deleted: number;
    seed: string | null;
    map: string | null;
    error: string | null;
    log: { at: string; message: string }[];
}

export interface PlansResponse {
    plans: Plan[];
    runs: Run[];
    max_plans: number;
}

export interface AdminOverview {
    upcoming: {
        server: { id: number; name: string; uuidShort: string } | null;
        plan: string;
        repeat: Repeat;
        next_run_at: string | null;
        skipping_next: boolean;
    }[];
    recent: (Run & { server: { id: number; name: string; uuidShort: string } | null })[];
}

export const ACTIVE: RunStatus[] = ['warning', 'stopping', 'backing_up', 'wiping', 'starting'];

const base = (uuid: string) => `/api/client/servers/${uuid}/extensions/wipe-season-manager`;

export const plansQuery = (uuid: string) =>
    queryOptions({
        queryKey: ['wipe-season-manager', uuid],
        queryFn: async () => (await http.get<PlansResponse>(`${base(uuid)}/plans`)).data,
        // Poll while a wipe is in progress so the steps update on their own.
        refetchInterval: (query) => (query.state.data?.runs.some((run) => ACTIVE.includes(run.status)) ? 10_000 : false),
    });

export const adminOverviewQuery = () =>
    queryOptions({
        queryKey: ['wipe-season-manager', 'admin'],
        queryFn: async () =>
            (await http.get<AdminOverview>('/api/admin/extensions/wipe-season-manager/overview')).data,
    });

export async function savePlan(uuid: string, input: PlanInput, id?: number): Promise<Plan> {
    const response = id
        ? await http.put<{ data: Plan }>(`${base(uuid)}/plans/${id}`, input)
        : await http.post<{ data: Plan }>(`${base(uuid)}/plans`, input);

    return response.data.data;
}

export async function deletePlan(uuid: string, id: number): Promise<void> {
    await http.delete(`${base(uuid)}/plans/${id}`);
}

export async function runPlan(uuid: string, id: number): Promise<void> {
    await http.post(`${base(uuid)}/plans/${id}/run`);
}

export async function skipPlan(uuid: string, id: number, skip: boolean): Promise<void> {
    await http.post(`${base(uuid)}/plans/${id}/skip`, { skip });
}

export async function cancelRun(uuid: string, id: number): Promise<void> {
    await http.post(`${base(uuid)}/runs/${id}/cancel`);
}
