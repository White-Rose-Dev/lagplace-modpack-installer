import React from 'react';
import tw from 'twin.macro';
import Portal from '@/components/elements/Portal';

/** Modal do projeto: overlay blur(1px) brightness(0.92), fundo rgb(10, 40, 50), 480px (não usa o Modal nativo do Pterodactyl). */
export default ({
    onClose,
    closeOnOutside = true,
    children,
}: {
    onClose: () => void;
    closeOnOutside?: boolean;
    children: React.ReactNode;
}) => (
    <Portal>
        <div
            css={tw`fixed inset-0 z-50 flex items-center justify-center`}
            style={{ backdropFilter: 'blur(1px) brightness(0.92)', WebkitBackdropFilter: 'blur(1px) brightness(0.92)' }}
            onMouseDown={(e) => {
                if (closeOnOutside && e.target === e.currentTarget) onClose();
            }}
        >
            <div
                css={tw`relative rounded-xl p-6`}
                style={{
                    background: 'rgb(10, 40, 50)',
                    border: '1px solid rgba(0,202,150,0.25)',
                    width: '100%',
                    maxWidth: '480px',
                    margin: '0 1rem',
                    maxHeight: '90vh',
                    overflowY: 'auto',
                }}
            >
                <button
                    type={'button'}
                    onClick={onClose}
                    css={tw`absolute top-4 right-4 text-neutral-400 hover:text-white`}
                    style={{ fontSize: '1.3rem', lineHeight: 1 }}
                >
                    ×
                </button>
                {children}
            </div>
        </div>
    </Portal>
);
