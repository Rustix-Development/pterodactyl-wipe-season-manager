import { useQuery } from '@tanstack/react-query';
import { PageContentBlock, Table } from '@pterodactyl/sdk';
import { adminOverviewQuery } from '../api';
import { countdown, REPEAT_LABELS, short, when } from '../format';
import StatusBadge from '../components/StatusBadge';

/** Every server's upcoming wipes and the latest results, for hosts. */
export default function AdminOverview() {
    const { data, isFetching } = useQuery(adminOverviewQuery());
    const now = Date.now();

    return (
        <PageContentBlock title='Wipes'>
            <h2 className='rxws:mt-0 rxws:mb-3 rxws:text-lg rxws:font-medium'>Upcoming</h2>
            <Table
                rows={data?.upcoming ?? []}
                keyOf={(row) => `${row.server?.id}-${row.plan}-${row.next_run_at}`}
                isFetching={isFetching}
                emptyState='No server has a wipe plan yet.'
                columns={[
                    { id: 'server', label: 'Server', cell: (row) => row.server?.name ?? 'Deleted server' },
                    { id: 'plan', label: 'Plan', cell: (row) => row.plan },
                    { id: 'repeat', label: 'Repeats', cell: (row) => REPEAT_LABELS[row.repeat] },
                    {
                        id: 'next',
                        label: 'Next wipe',
                        cell: (row) => (
                            <span>
                                {when(row.next_run_at)}
                                {row.next_run_at && countdown(row.next_run_at, now) && (
                                    <span className='rxws:ml-2 rxws:text-muted-foreground'>
                                        in {countdown(row.next_run_at, now)}
                                    </span>
                                )}
                            </span>
                        ),
                    },
                ]}
            />

            <h2 className='rxws:mt-8 rxws:mb-3 rxws:text-lg rxws:font-medium'>Recent</h2>
            <Table
                rows={data?.recent ?? []}
                keyOf={(row) => String(row.id)}
                isFetching={isFetching}
                emptyState='No wipes have run yet.'
                columns={[
                    {
                        id: 'when',
                        label: 'When',
                        cell: (row) => <span className='rxws:whitespace-nowrap'>{short(row.started_at ?? row.scheduled_for)}</span>,
                    },
                    { id: 'server', label: 'Server', cell: (row) => row.server?.name ?? 'Deleted server' },
                    { id: 'plan', label: 'Plan', cell: (row) => row.plan_name },
                    { id: 'status', label: 'Result', cell: (row) => <StatusBadge status={row.status} /> },
                    {
                        id: 'error',
                        label: 'Details',
                        cell: (row) => (
                            <span className='rxws:block rxws:max-w-sm rxws:truncate rxws:text-sm rxws:text-muted-foreground'>
                                {row.error ?? (row.files_deleted ? `${row.files_deleted} deleted` : '')}
                            </span>
                        ),
                    },
                ]}
            />
        </PageContentBlock>
    );
}
