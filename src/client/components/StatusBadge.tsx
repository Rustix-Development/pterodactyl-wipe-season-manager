import type { RunStatus } from '../api';
import { STATUS_LABELS } from '../format';

const TONE: Partial<Record<RunStatus, string>> = {
    done: 'rxws:bg-success rxws:text-success-foreground',
    failed: 'rxws:bg-destructive rxws:text-destructive-foreground',
    missed: 'rxws:bg-warning rxws:text-warning-foreground',
};

export default function StatusBadge({ status }: { status: RunStatus }) {
    return (
        <span
            className={`rxws:inline-block rxws:whitespace-nowrap rxws:rounded rxws:px-2 rxws:py-0.5 rxws:text-xs rxws:font-medium ${
                TONE[status] ?? 'rxws:bg-muted rxws:text-muted-foreground'
            }`}
        >
            {STATUS_LABELS[status]}
        </span>
    );
}
