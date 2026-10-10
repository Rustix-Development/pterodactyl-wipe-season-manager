import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Button,
    Dialog,
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
    httpErrorToHuman,
    ServerContentBlock,
    Spinner,
    Table,
    toast,
    useCurrentServerRequired,
} from '@pterodactyl/sdk';
import {
    ACTIVE,
    cancelRun,
    deletePlan,
    plansQuery,
    runPlan,
    savePlan,
    skipPlan,
    type Plan,
    type PlanInput,
    type Run,
} from '../api';
import { isDiscordAllowed } from '../config';
import { countdown, REPEAT_LABELS, short, when } from '../format';
import RunProgress from '../components/RunProgress';
import StatusBadge from '../components/StatusBadge';
import PlanForm from './PlanForm';

const useNow = (ms: number) => {
    const [now, setNow] = useState(Date.now());
    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), ms);

        return () => clearInterval(timer);
    }, [ms]);

    return now;
};

export default function WipesScreen() {
    const server = useCurrentServerRequired();
    const uuid = server.attributes.uuid;
    const client = useQueryClient();
    const now = useNow(30_000);
    const { data, isLoading, isFetching } = useQuery(plansQuery(uuid));
    const [editing, setEditing] = useState<Plan | null | undefined>(undefined);
    const [deleting, setDeleting] = useState<Plan | null>(null);
    const [running, setRunning] = useState<Plan | null>(null);
    const [details, setDetails] = useState<Run | null>(null);

    const refresh = () => client.invalidateQueries({ queryKey: ['wipe-season-manager', uuid] });
    const onError = (error: unknown) => toast.error(httpErrorToHuman(error));

    const save = useMutation({
        mutationFn: (input: PlanInput) => savePlan(uuid, input, editing?.id),
        onSuccess: async () => {
            toast.success('Plan saved.');
            setEditing(undefined);
            await refresh();
        },
        onError,
    });
    const remove = useMutation({
        mutationFn: (id: number) => deletePlan(uuid, id),
        onSuccess: async () => {
            setDeleting(null);
            await refresh();
        },
        onError,
    });
    const run = useMutation({
        mutationFn: (id: number) => runPlan(uuid, id),
        onSuccess: async () => {
            toast.success('Wipe started.');
            setRunning(null);
            await refresh();
        },
        onError,
    });
    const skip = useMutation({
        mutationFn: ({ id, value }: { id: number; value: boolean }) => skipPlan(uuid, id, value),
        onSuccess: refresh,
        onError,
    });
    const cancel = useMutation({
        mutationFn: (id: number) => cancelRun(uuid, id),
        onSuccess: refresh,
        onError,
    });

    const plans = data?.plans ?? [];
    const runs = data?.runs ?? [];
    const active = runs.find((r) => ACTIVE.includes(r.status));
    const canAdd = plans.length < (data?.max_plans ?? 5);

    return (
        <ServerContentBlock title='Wipes'>
            <div className='rxws:mb-4 rxws:flex rxws:flex-wrap rxws:items-center rxws:justify-between rxws:gap-3'>
                <h2 className='rxws:m-0 rxws:text-lg rxws:font-medium'>Wipe plans</h2>
                {canAdd && <Button onClick={() => setEditing(null)}>New plan</Button>}
            </div>

            {active && (
                <section
                    aria-live='polite'
                    className='rxws:mb-6 rxws:flex rxws:flex-col rxws:gap-3 rxws:rounded-md rxws:border rxws:border-primary rxws:bg-card rxws:p-4'
                >
                    <div className='rxws:flex rxws:flex-wrap rxws:items-center rxws:justify-between rxws:gap-2'>
                        <p className='rxws:m-0 rxws:font-medium'>
                            {active.plan_name}: {active.status === 'warning' ? `wipes ${when(active.scheduled_for)}` : 'wiping now'}
                        </p>
                        {active.status === 'warning' && (
                            <Button.Text size='small' isLoading={cancel.isPending} onClick={() => cancel.mutate(active.id)}>
                                Cancel this wipe
                            </Button.Text>
                        )}
                    </div>
                    <RunProgress run={active} now={now} />
                </section>
            )}

            {isLoading ? (
                <Spinner centered />
            ) : plans.length === 0 ? (
                <Empty className='rxws:border'>
                    <EmptyHeader>
                        <EmptyTitle>No wipe plans yet</EmptyTitle>
                        <EmptyDescription>
                            A plan wipes this server on a schedule: it warns players, backs up, deletes the files you
                            choose, can change the seed or map, and starts the server again.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <ul className='rxws:m-0 rxws:grid rxws:list-none rxws:gap-3 rxws:p-0 rxws:lg:grid-cols-2'>
                    {plans.map((plan) => {
                        const left = plan.next_run_at ? countdown(plan.next_run_at, now) : null;

                        return (
                            <li
                                key={plan.id}
                                className='rxws:flex rxws:min-w-0 rxws:flex-col rxws:gap-3 rxws:rounded-md rxws:border rxws:border-border rxws:bg-card rxws:p-4'
                            >
                                <div className='rxws:flex rxws:items-start rxws:justify-between rxws:gap-3'>
                                    <div className='rxws:min-w-0'>
                                        <p className='rxws:m-0 rxws:truncate rxws:font-medium'>{plan.name}</p>
                                        <p className='rxws:m-0 rxws:text-sm rxws:text-muted-foreground'>
                                            {REPEAT_LABELS[plan.repeat]} · {plan.timezone}
                                        </p>
                                    </div>
                                    {!plan.enabled && (
                                        <span className='rxws:rounded rxws:bg-muted rxws:px-2 rxws:py-0.5 rxws:text-xs rxws:text-muted-foreground'>
                                            Off
                                        </span>
                                    )}
                                </div>
                                <div>
                                    <p className='rxws:m-0 rxws:text-xs rxws:uppercase rxws:tracking-wide rxws:text-muted-foreground'>
                                        {plan.skipping_next ? 'Next wipe (one skipped)' : 'Next wipe'}
                                    </p>
                                    <p className='rxws:m-0 rxws:text-base'>
                                        {plan.enabled ? when(plan.next_run_at) : 'Paused'}
                                        {plan.enabled && left && (
                                            <span className='rxws:ml-2 rxws:text-sm rxws:text-muted-foreground'>in {left}</span>
                                        )}
                                    </p>
                                </div>
                                <div className='rxws:mt-auto rxws:flex rxws:flex-wrap rxws:gap-2'>
                                    <Button size='small' isSecondary onClick={() => setEditing(plan)}>
                                        Edit
                                    </Button>
                                    {plan.enabled && plan.next_run_at && (
                                        <Button
                                            size='small'
                                            isSecondary
                                            disabled={skip.isPending}
                                            onClick={() => skip.mutate({ id: plan.id, value: !plan.skipping_next })}
                                        >
                                            {plan.skipping_next ? 'Undo skip' : 'Skip next'}
                                        </Button>
                                    )}
                                    <Button size='small' isSecondary disabled={!!active} onClick={() => setRunning(plan)}>
                                        Wipe now
                                    </Button>
                                    <Button.Danger size='small' className='rxws:ml-auto' onClick={() => setDeleting(plan)}>
                                        Delete
                                    </Button.Danger>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}

            <h2 className='rxws:mt-8 rxws:mb-3 rxws:text-lg rxws:font-medium'>History</h2>
            <Table
                rows={runs}
                keyOf={(r) => String(r.id)}
                isFetching={isFetching}
                emptyState='No wipes have run yet.'
                columns={[
                    {
                        id: 'when',
                        label: 'When',
                        cell: (r) => <span className='rxws:whitespace-nowrap'>{short(r.started_at ?? r.scheduled_for)}</span>,
                    },
                    { id: 'plan', label: 'Plan', cell: (r) => r.plan_name },
                    { id: 'status', label: 'Result', cell: (r) => <StatusBadge status={r.status} /> },
                    {
                        id: 'summary',
                        label: 'Details',
                        cell: (r) => (
                            <span className='rxws:block rxws:max-w-sm rxws:truncate rxws:text-sm rxws:text-muted-foreground'>
                                {r.error ??
                                    [
                                        r.files_deleted ? `${r.files_deleted} deleted` : null,
                                        r.seed ? `seed ${r.seed}` : null,
                                        r.trigger === 'manual' ? 'started by hand' : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                            </span>
                        ),
                    },
                    {
                        id: 'log',
                        label: '',
                        cell: (r) => (
                            <div className='rxws:flex rxws:justify-end'>
                                <Button.Text size='small' onClick={() => setDetails(r)}>
                                    Log
                                </Button.Text>
                            </div>
                        ),
                    },
                ]}
            />

            <Dialog
                open={editing !== undefined}
                onClose={() => setEditing(undefined)}
                title={editing ? `Edit ${editing.name}` : 'New wipe plan'}
            >
                {editing !== undefined && (
                    <PlanForm
                        initial={editing}
                        discordAllowed={isDiscordAllowed()}
                        pending={save.isPending}
                        onCancel={() => setEditing(undefined)}
                        onSubmit={(input) => save.mutate(input)}
                    />
                )}
            </Dialog>

            <Dialog.Confirm
                open={running !== null}
                onClose={() => setRunning(null)}
                title={`Wipe now with ${running?.name ?? ''}?`}
                confirm='Wipe now'
                pending={run.isPending}
                onConfirmed={() => running && run.mutate(running.id)}
            >
                The server stops straight away, without warnings to players. It is backed up first if the plan says
                so, then the files are deleted and it starts again.
            </Dialog.Confirm>

            <Dialog.Confirm
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                title='Delete plan?'
                confirm='Delete'
                pending={remove.isPending}
                onConfirmed={() => deleting && remove.mutate(deleting.id)}
            >
                “{deleting?.name}” stops running. Its past wipes stay in the history.
            </Dialog.Confirm>

            <Dialog open={details !== null} onClose={() => setDetails(null)} title={details?.plan_name ?? 'Wipe log'}>
                <ol className='rxws:m-0 rxws:flex rxws:list-none rxws:flex-col rxws:gap-1 rxws:p-0 rxws:text-sm'>
                    {details?.log.map((entry, i) => (
                        <li key={i} className='rxws:flex rxws:gap-3'>
                            <time className='rxws:shrink-0 rxws:font-mono rxws:text-xs rxws:leading-5 rxws:text-muted-foreground'>
                                {new Date(entry.at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}
                            </time>
                            <span>{entry.message}</span>
                        </li>
                    ))}
                </ol>
            </Dialog>
        </ServerContentBlock>
    );
}
