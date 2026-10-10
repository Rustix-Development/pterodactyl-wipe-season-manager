import type { ExtensionConfig } from '@pterodactyl/sdk';

let discordAllowed = true;

export function setConfig(config: ExtensionConfig): void {
    discordAllowed = config.allow_discord !== false;
}

export const isDiscordAllowed = (): boolean => discordAllowed;
