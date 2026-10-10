import { Check, Loader2 } from 'lucide-react';
import type { Run } from '../api';
import { countdown, STATUS_LABELS, STEPS } from '../format';

/** The steps of a wipe that is in progress, with the current one marked. */
export default function RunProgress({ run, now }: { run: Run; now: number }) {
    const current = STEPS.indexOf(run.status);
    const left = run.status === 'warning' ? countdown(run.scheduled_for, now) : null;

    return (
        <ol className='rxws:m-0 rxws:flex rxws:list-none rxws:flex-wrap rxws:gap-x-5 rxws:gap-y-2 rxws:p-0 rxws:text-sm'>
            {STEPS.map((step, index) => {
                const done = index < current;
                const active = index === current;

                return (
                    <li
                        key={step}
                        aria-current={active ? 'step' : undefined}
                        className={`rxws:inline-flex rxws:items-center rxws:gap-1.5 ${
                            active ? 'rxws:font-medium rxws:text-foreground' : done ? 'rxws:text-success' : 'rxws:text-muted-foreground'
                        }`}
                    >
                        {done ? (
                            <Check size={14} aria-hidden='true' />
                        ) : active ? (
                            <Loader2 size={14} aria-hidden='true' className='rxws:animate-spin rxws:motion-reduce:animate-none' />
                        ) : (
                            <span aria-hidden='true' className='rxws:inline-block rxws:size-3.5 rxws:rounded-full rxws:border rxws:border-border' />
                        )}
                        {STATUS_LABELS[step]}
                        {active && left ? ` (${left})` : ''}
                    </li>
                );
            })}
        </ol>
    );
}
