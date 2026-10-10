import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { Button, Dialog, Input, Label, Select, Switch, TextArea } from '@pterodactyl/sdk';
import type { Plan, PlanInput, Repeat, SeedMode } from '../api';
import { lines, parseMinutes, REPEAT_LABELS, timezones } from '../format';
import { PRESETS } from '../presets';

interface Props {
    initial: Plan | null;
    discordAllowed: boolean;
    pending: boolean;
    onCancel: () => void;
    onSubmit: (input: PlanInput) => void;
}

const fromPlan = (plan: Plan): PlanInput => ({ ...plan, discord_webhook: null });

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <fieldset className='rxws:m-0 rxws:flex rxws:min-w-0 rxws:flex-col rxws:gap-4 rxws:border-0 rxws:border-t rxws:border-border rxws:p-0 rxws:pt-4'>
            <legend className='rxws:mb-1 rxws:pr-2 rxws:text-xs rxws:font-medium rxws:uppercase rxws:tracking-wide rxws:text-muted-foreground'>
                {title}
            </legend>
            {children}
        </fieldset>
    );
}

function Field({ id, label, hint, children }: { id: string; label: string; hint?: string; children: ReactNode }) {
    return (
        <div className='rxws:min-w-0'>
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && <p className='rxws:mt-1 rxws:text-xs rxws:text-muted-foreground'>{hint}</p>}
        </div>
    );
}

export default function PlanForm({ initial, discordAllowed, pending, onCancel, onSubmit }: Props) {
    const [values, setValues] = useState<PlanInput>(() => (initial ? fromPlan(initial) : PRESETS[0]!.build()));
    const [warnText, setWarnText] = useState(values.warn_minutes.join(', '));
    const [pathsText, setPathsText] = useState(values.paths.join('\n'));
    const [seedsText, setSeedsText] = useState(values.seed_list.join('\n'));
    const [mapsText, setMapsText] = useState(values.map_list.join('\n'));
    const [removeWebhook, setRemoveWebhook] = useState(false);
    const zones = useMemo(() => {
        const list = timezones();
        // Browsers leave UTC and some aliases out of their list, so keep the saved zone selectable.
        return (list.includes(values.timezone) ? list : [values.timezone, ...list]).map((zone) => ({ value: zone, label: zone }));
    }, [values.timezone]);

    const set = <K extends keyof PlanInput>(key: K, value: PlanInput[K]) => setValues((v) => ({ ...v, [key]: value }));

    const applyPreset = (id: string) => {
        const preset = PRESETS.find((p) => p.id === id);
        if (!preset) return;
        const next = preset.build();
        setValues(next);
        setWarnText(next.warn_minutes.join(', '));
        setPathsText(next.paths.join('\n'));
        setSeedsText(next.seed_list.join('\n'));
        setMapsText(next.map_list.join('\n'));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        onSubmit({
            ...values,
            name: values.name.trim(),
            warn_minutes: parseMinutes(warnText),
            warn_command: values.warn_command?.trim() || null,
            paths: lines(pathsText),
            seed_variable: values.seed_variable?.trim().toUpperCase() || null,
            seed_list: lines(seedsText),
            map_variable: values.map_variable?.trim().toUpperCase() || null,
            map_list: lines(mapsText),
            discord_webhook: removeWebhook ? '' : values.discord_webhook?.trim() || null,
            discord_message: values.discord_message?.trim() || null,
        });
    };

    return (
        <form onSubmit={submit} className='rxws:flex rxws:flex-col rxws:gap-4'>
            {!initial && (
                <Field id='rxws-preset' label='Game'>
                    <Select
                        id='rxws-preset'
                        value={values.game}
                        options={PRESETS.map((p) => ({ value: p.id, label: p.label }))}
                        onChange={(v) => applyPreset(String(v))}
                    />
                </Field>
            )}

            <div className='rxws:grid rxws:gap-4 rxws:sm:grid-cols-2'>
                <Field id='rxws-name' label='Name'>
                    <Input
                        id='rxws-name'
                        value={values.name}
                        maxLength={60}
                        required
                        onChange={(e) => set('name', e.target.value)}
                    />
                </Field>
                <Field id='rxws-repeat' label='Repeats'>
                    <Select
                        id='rxws-repeat'
                        value={values.repeat}
                        options={Object.entries(REPEAT_LABELS).map(([value, label]) => ({ value, label }))}
                        onChange={(v) => set('repeat', v as Repeat)}
                    />
                </Field>
                <Field id='rxws-anchor' label='First wipe'>
                    <Input
                        id='rxws-anchor'
                        type='datetime-local'
                        value={values.anchor_local}
                        required
                        onChange={(e) => set('anchor_local', e.target.value)}
                    />
                </Field>
                <Field id='rxws-tz' label='Time zone'>
                    <Select id='rxws-tz' value={values.timezone} options={zones} onChange={(v) => set('timezone', String(v))} />
                </Field>
            </div>
            <Field
                id='rxws-skip'
                label='Skip when another wipe is within (hours)'
                hint='For example, skip the weekly map wipe in the force wipe week. Leave empty to never skip.'
            >
                <Input
                    id='rxws-skip'
                    type='number'
                    min={1}
                    max={336}
                    value={values.skip_within_hours ?? ''}
                    onChange={(e) => set('skip_within_hours', e.target.value ? Number(e.target.value) : null)}
                />
            </Field>
            <Switch checked={values.enabled} onChange={(v) => set('enabled', v)} label='Enabled' />

            <Section title='Warnings'>
                <div className='rxws:grid rxws:gap-4 rxws:sm:grid-cols-[10rem_1fr]'>
                    <Field id='rxws-warn' label='Minutes before'>
                        <Input id='rxws-warn' value={warnText} onChange={(e) => setWarnText(e.target.value)} />
                    </Field>
                    <Field id='rxws-warn-cmd' label='Console command' hint='{time} becomes "in 5 minutes", then "now".'>
                        <Input
                            id='rxws-warn-cmd'
                            value={values.warn_command ?? ''}
                            maxLength={255}
                            onChange={(e) => set('warn_command', e.target.value)}
                        />
                    </Field>
                </div>
            </Section>

            <Section title='Wipe'>
                <Switch
                    checked={values.backup}
                    onChange={(v) => set('backup', v)}
                    label='Back up first'
                    description='Nothing is deleted unless the backup succeeds. Uses the server’s backup slots, replacing the oldest unlocked backup when full.'
                />
                <Field
                    id='rxws-paths'
                    label='Files and folders to delete'
                    hint='One per line, from the server’s root. Wildcards (*) only in the last part.'
                >
                    <TextArea
                        id='rxws-paths'
                        rows={6}
                        className='rxws:font-mono rxws:text-xs'
                        value={pathsText}
                        onChange={(e) => setPathsText(e.target.value)}
                    />
                </Field>
            </Section>

            <Section title='Seed and map'>
                <div className='rxws:grid rxws:gap-4 rxws:sm:grid-cols-2'>
                    <Field id='rxws-seed-var' label='Seed variable'>
                        <Input
                            id='rxws-seed-var'
                            placeholder='WORLD_SEED'
                            value={values.seed_variable ?? ''}
                            onChange={(e) => set('seed_variable', e.target.value)}
                        />
                    </Field>
                    <Field id='rxws-seed-mode' label='New seed'>
                        <Select
                            id='rxws-seed-mode'
                            value={values.seed_mode}
                            options={[
                                { value: 'keep', label: 'Keep the current seed' },
                                { value: 'random', label: 'Random every wipe' },
                                { value: 'list', label: 'Next from a list' },
                            ]}
                            onChange={(v) => set('seed_mode', v as SeedMode)}
                        />
                    </Field>
                </div>
                {values.seed_mode === 'list' && (
                    <Field id='rxws-seeds' label='Seeds, one per line'>
                        <TextArea id='rxws-seeds' rows={3} value={seedsText} onChange={(e) => setSeedsText(e.target.value)} />
                    </Field>
                )}
                <div className='rxws:grid rxws:gap-4 rxws:sm:grid-cols-[12rem_1fr]'>
                    <Field id='rxws-map-var' label='Map variable'>
                        <Input
                            id='rxws-map-var'
                            placeholder='MAP_URL'
                            value={values.map_variable ?? ''}
                            onChange={(e) => set('map_variable', e.target.value)}
                        />
                    </Field>
                    <Field id='rxws-maps' label='Maps to rotate, one per line' hint='Leave empty to keep the current map.'>
                        <TextArea id='rxws-maps' rows={2} value={mapsText} onChange={(e) => setMapsText(e.target.value)} />
                    </Field>
                </div>
            </Section>

            {discordAllowed && (
                <Section title='Discord'>
                    <Field
                        id='rxws-webhook'
                        label='Webhook'
                        hint={initial?.discord_webhook && !removeWebhook ? `Saved (${initial.discord_webhook}). Leave empty to keep it.` : undefined}
                    >
                        <Input
                            id='rxws-webhook'
                            type='url'
                            placeholder='https://discord.com/api/webhooks/…'
                            value={values.discord_webhook ?? ''}
                            disabled={removeWebhook}
                            onChange={(e) => set('discord_webhook', e.target.value)}
                        />
                    </Field>
                    {initial?.discord_webhook && (
                        <Switch checked={removeWebhook} onChange={setRemoveWebhook} label='Remove the saved webhook' />
                    )}
                    <Field id='rxws-message' label='Message' hint='{server}, {plan}, {seed}, {map} and {next} are filled in.'>
                        <TextArea
                            id='rxws-message'
                            rows={2}
                            maxLength={1000}
                            placeholder='**{server}** has wiped ({plan}). Next wipe: {next}.'
                            value={values.discord_message ?? ''}
                            onChange={(e) => set('discord_message', e.target.value)}
                        />
                    </Field>
                </Section>
            )}

            <Dialog.Footer>
                <Button isSecondary onClick={onCancel} disabled={pending}>
                    Cancel
                </Button>
                <Button type='submit' isLoading={pending}>
                    Save
                </Button>
            </Dialog.Footer>
        </form>
    );
}
