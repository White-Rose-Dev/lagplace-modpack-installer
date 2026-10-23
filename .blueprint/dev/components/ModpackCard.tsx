import React from 'react';
import { Button } from '@/components/elements/button';
import ResourceCard from '@/components/lagplace/ResourceCard';
import { ModpackHit } from './modpacks';

const LOADER_NAMES: Record<string, string> = { forge: 'Forge', neoforge: 'NeoForge', fabric: 'Fabric', quilt: 'Quilt' };

const compact = (n: number) => new Intl.NumberFormat('en', { notation: 'compact' }).format(n);

export default ({ pack, onInstall, disabled }: { pack: ModpackHit; onInstall: () => void; disabled: boolean }) => (
    <ResourceCard
        icon={pack.icon}
        title={pack.title}
        url={pack.url}
        chips={pack.loaders.map((loader) => ({ key: loader, label: LOADER_NAMES[loader] || loader }))}
        description={pack.description}
        meta={
            <>
                <span>{compact(pack.downloads)}</span> <span>downloads</span>
                {pack.versions.length > 0 && (
                    <>
                        {' · '}
                        <span>Minecraft</span> <span>{pack.versions[pack.versions.length - 1]}</span>
                    </>
                )}
            </>
        }
        actions={
            <Button type={'button'} onClick={onInstall} disabled={disabled}>
                Install
            </Button>
        }
    />
);
