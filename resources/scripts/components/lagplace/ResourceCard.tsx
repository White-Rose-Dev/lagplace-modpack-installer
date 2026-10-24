import React, { useState } from 'react';
import tw from 'twin.macro';
import { css } from 'styled-components/macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCube, faExternalLinkAlt } from '@fortawesome/free-solid-svg-icons';

// Visual comum das páginas de pesquisa/instalação (Modpacks, Mods, Plugins): um só sítio para o estilo.
export const strip = css`
    border: 1px solid rgba(0, 202, 150, 0.14);
    border-left: 4px solid rgba(0, 202, 150, 0.5);
    background: rgba(3, 28, 34, 0.45);
    border-radius: 0.65rem;
`;

export const grid = css`
    display: grid;
    gap: 0.6rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    @media (max-width: 1200px) {
        grid-template-columns: minmax(0, 1fr);
    }
`;

export const field = css`
    ${tw`rounded-lg px-3 py-2 bg-black bg-opacity-30 border border-neutral-600 text-neutral-100`};
    height: 2.75rem;
    &:focus {
        outline: none;
        border-color: rgba(0, 202, 150, 0.6);
    }
`;

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

export const Filter = ({ label, htmlFor, children }: { label: string; htmlFor: string; children: React.ReactNode }) => (
    <div>
        <label htmlFor={htmlFor} css={tw`block text-xs uppercase tracking-wide text-neutral-400 mb-1`}>
            {label}
        </label>
        {children}
    </div>
);

// Link externo com o aspeto de um botão (ex.: mods do CurseForge que o autor não deixa instalar daqui).
const outline = css`
    display: inline-flex;
    align-items: center;
    padding: 0.6rem 1.1rem;
    border-radius: 9999px;
    border: 1px solid rgba(0, 202, 150, 0.6);
    color: #7fe9cd;
    font-weight: 600;
    transition: background 0.15s ease;
    &:hover {
        background: rgba(0, 202, 150, 0.15);
    }
`;

export const ExternalButton = ({ href, title, children }: { href: string; title?: string; children: React.ReactNode }) => (
    <a href={href} title={title} target={'_blank'} rel={'noreferrer'} css={outline}>
        {children} <FontAwesomeIcon icon={faExternalLinkAlt} css={tw`ml-2 h-3 w-3`} />
    </a>
);

const dangerIcon = css`
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 9999px;
    border: 1px solid rgba(239, 68, 68, 0.55);
    background: transparent;
    color: #f87171;
    transition: background 0.15s ease, color 0.15s ease;
    &:hover {
        background: rgba(239, 68, 68, 0.18);
        color: #fecaca;
    }
`;

export const DangerIconButton = ({ title, onClick, children }: { title: string; onClick: () => void; children: React.ReactNode }) => (
    <button type={'button'} title={title} aria-label={title} onClick={onClick} css={dangerIcon}>
        {children}
    </button>
);

interface Props {
    icon?: string | null;
    title: string;
    url?: string | null;
    chips?: { key: string; label: string }[];
    description?: string | null;
    meta?: React.ReactNode;
    actions: React.ReactNode;
}

export default ({ icon, title, url, chips = [], description, meta, actions }: Props) => {
    const [broken, setBroken] = useState(false);

    return (
        <div css={card}>
            <div
                style={{
                    width: '3.25rem',
                    height: '3.25rem',
                    flexShrink: 0,
                    borderRadius: '0.6rem',
                    background: 'rgba(0,0,0,0.3)',
                    overflow: 'hidden',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                }}
            >
                {icon && !broken ? (
                    <img
                        src={icon}
                        alt={''}
                        onError={() => setBroken(true)}
                        style={{ width: '100%', height: '100%', objectFit: 'cover' }}
                        loading={'lazy'}
                    />
                ) : (
                    <FontAwesomeIcon icon={faCube} style={{ color: 'rgba(0,202,150,0.45)', fontSize: '1.4rem' }} />
                )}
            </div>

            <div style={{ minWidth: 0, flex: 1 }}>
                <div css={tw`flex items-center flex-wrap gap-2`}>
                    <p css={tw`text-base font-semibold text-white`} style={{ wordBreak: 'break-word' }}>
                        {url ? (
                            <a href={url} target={'_blank'} rel={'noreferrer'} css={tw`hover:text-gray-400`}>
                                {title} <FontAwesomeIcon icon={faExternalLinkAlt} css={tw`ml-1 h-3 w-3`} />
                            </a>
                        ) : (
                            title
                        )}
                    </p>
                    {chips.map((chip) => (
                        <span
                            key={chip.key}
                            css={tw`text-xs rounded px-2 py-px`}
                            style={{ background: 'rgba(0,202,150,0.15)', color: '#7fe9cd' }}
                        >
                            {chip.label}
                        </span>
                    ))}
                </div>
                {description && (
                    <p
                        css={tw`text-sm text-neutral-300 mt-1`}
                        style={{ display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}
                    >
                        {description}
                    </p>
                )}
                {meta && <p css={tw`text-xs text-neutral-400 mt-1`}>{meta}</p>}
            </div>

            <div css={tw`flex items-center gap-2`} style={{ flexShrink: 0 }}>
                {actions}
            </div>
        </div>
    );
};
