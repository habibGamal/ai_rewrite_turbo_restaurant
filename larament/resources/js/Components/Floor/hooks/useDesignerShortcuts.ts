import { useEffect } from 'react';
import { Modal, message } from 'antd';
import type { FloorLayout } from '@/types';
import {
    nudgeSelection,
    type SelectionTarget,
} from '@/Components/Floor/floorLayout';

export interface UseDesignerShortcutsOptions {
    layout: FloorLayout;
    selection: SelectionTarget | null;
    onUndo: () => void;
    onRedo: () => void;
    onEscape: () => void;
    onDeleteSelection: () => void;
    onCommitLayout: (nextLayout: FloorLayout) => void;
}

export function useDesignerShortcuts({
    layout,
    selection,
    onUndo,
    onRedo,
    onEscape,
    onDeleteSelection,
    onCommitLayout,
}: UseDesignerShortcutsOptions): void {
    useEffect(() => {
        const handleKeyDown = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            if (
                target &&
                (target.tagName === 'INPUT' ||
                    target.tagName === 'TEXTAREA' ||
                    target.isContentEditable)
            ) {
                return;
            }

            const keyLower = event.key.toLowerCase();
            if ((event.ctrlKey || event.metaKey) && keyLower === 'z') {
                event.preventDefault();
                if (event.shiftKey) {
                    onRedo();
                } else {
                    onUndo();
                }
                return;
            }

            if ((event.ctrlKey || event.metaKey) && keyLower === 'y') {
                event.preventDefault();
                onRedo();
                return;
            }

            if (event.key === 'Escape') {
                onEscape();
                return;
            }

            if (!selection) {
                return;
            }

            if (event.key === 'Delete' || event.key === 'Backspace') {
                event.preventDefault();
                const itemName =
                    selection.kind === 'table'
                        ? `الطاولة ${layout.tables[selection.index]?.number ?? ''}`
                        : `القسم "${layout.sections[selection.index]?.name ?? ''}"`;

                Modal.confirm({
                    title: `حذف ${itemName}`,
                    content: `هل أنت متأكد من حذف ${itemName}؟`,
                    okText: 'حذف',
                    cancelText: 'إلغاء',
                    okButtonProps: { danger: true },
                    onOk: () => onDeleteSelection(),
                });
                return;
            }

            let dCol = 0;
            let dRow = 0;

            if (event.key === 'ArrowRight') {
                dCol = -1;
            } else if (event.key === 'ArrowLeft') {
                dCol = 1;
            } else if (event.key === 'ArrowUp') {
                dRow = -1;
            } else if (event.key === 'ArrowDown') {
                dRow = 1;
            } else {
                return;
            }

            event.preventDefault();
            const nudged = nudgeSelection(layout, selection, dCol, dRow);
            if (nudged) {
                onCommitLayout(nudged);
            } else {
                message.warning('لا يمكن التحريك خارج الشبكة أو فوق عنصر آخر');
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => {
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [
        layout,
        onCommitLayout,
        onDeleteSelection,
        onEscape,
        onRedo,
        onUndo,
        selection,
    ]);
}
