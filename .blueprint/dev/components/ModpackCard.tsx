import React from 'react';
import tw from 'twin.macro';
import { css } from 'styled-components/macro';
import { Button } from '@/components/elements/button';
import { ModpackHit } from './modpacks';

const card = css`
    display: flex;
    align-items: center;
    gap: 0.9rem;
    height: 100%;
    min-width: 0;
    padding: 0.8rem 0.95rem;
    border-radius: 0.65rem;
    border: 1px solid rgba(0, 202, 150, 0.14);
    border-left: 4px solid rgba(0, 202, 150, 0.45);
    background: rgba(3, 28, 34, 0.45);
    transition: background 0.15s ease, border-color 0.15s ease;
    &:hover {
        border-color: rgba(0, 202, 150, 0.45);
        background: rgba(0, 202, 150, 0.07);
    }
`;

const LOADER_NAMES: Record<string, string> = { forge: 'Forge', neoforge: 'NeoForge', fabric: 'Fabric', quilt: 'Quilt' };

const compact = (n: number) => new Intl.NumberFormat('en', { notation: 'compact' }).format(n);

export default ({ pack, onInstall, disabled }: { pack: ModpackHit; onInstall: () => void; disabled: boolean }) => (
    <div css={card}>
        <div
            style={{
                width: '3.25rem',
                height: '3.25rem',
                flexShrink: 0,
                borderRadius: '0.6rem',
                background: 'rgba(0,0,0,0.3)',
                overflow: 'hidden',
            }}
        >
            {pack.icon && (
                <img src={pack.icon} alt={''} style={{ width: '100%', height: '100%', objectFit: 'cover' }} loading={'lazy'} />
            )}
        </div>

        <div style={{ minWidth: 0, flex: 1 }}>
            <div css={tw`flex items-center flex-wrap gap-2`}>
                <p css={tw`text-base font-semibold text-white`} style={{ wordBreak: 'break-word' }}>
                    {pack.title}
                </p>
                {pack.loaders.map((loader) => (
                    <span
                        key={loader}
                        css={tw`text-xs rounded px-2 py-px`}
                        style={{ background: 'rgba(0,202,150,0.15)', color: '#7fe9cd' }}
                    >
                        {LOADER_NAMES[loader] || loader}
                    </span>
                ))}
            </div>
            <p
                css={tw`text-sm text-neutral-300 mt-1`}
                style={{ display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}
            >
                {pack.description}
            </p>
            <p css={tw`text-xs text-neutral-400 mt-1`}>
                <span>{compact(pack.downloads)}</span> <span>downloads</span>
                {pack.versions.length > 0 && (
                    <>
                        {' · '}
                        <span>Minecraft</span> <span>{pack.versions[pack.versions.length - 1]}</span>
                    </>
                )}
            </p>
        </div>

        <Button type={'button'} onClick={onInstall} disabled={disabled}>
            Install
        </Button>
    </div>
);
