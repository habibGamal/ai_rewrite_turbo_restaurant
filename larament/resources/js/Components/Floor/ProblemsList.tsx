import React from 'react';
import { Alert } from 'antd';
import { ExclamationCircleOutlined } from '@ant-design/icons';
import type {
    LayoutProblem,
    SelectionTarget,
} from '@/Components/Floor/floorLayout';

export interface ProblemsListProps {
    problems: LayoutProblem[];
    serverErrorMessages: string[];
    onSelectProblemTarget: (target: SelectionTarget) => void;
}

export function ProblemsList({
    problems,
    serverErrorMessages,
    onSelectProblemTarget,
}: ProblemsListProps) {
    if (problems.length === 0 && serverErrorMessages.length === 0) {
        return null;
    }

    return (
        <div className="mt-auto pt-3 border-t border-slate-200 space-y-2">
            {serverErrorMessages.length > 0 && (
                <Alert
                    type="error"
                    showIcon
                    message="خطأ في الحفظ من الخادم"
                    description={serverErrorMessages.join('، ')}
                />
            )}

            {problems.length > 0 && (
                <div className="bg-red-50 border border-red-200 rounded-lg p-3 space-y-2">
                    <div className="flex items-center gap-1.5 text-red-700 text-xs font-semibold">
                        <ExclamationCircleOutlined />
                        <span>مشكلات تمنع الحفظ ({problems.length})</span>
                    </div>
                    <div className="space-y-1 max-h-40 overflow-y-auto">
                        {problems.map((problem) => (
                            <button
                                key={problem.id}
                                type="button"
                                onClick={() => {
                                    if (problem.target) {
                                        onSelectProblemTarget(problem.target);
                                    }
                                }}
                                className="w-full text-right text-xs text-red-800 hover:bg-red-100 px-2 py-1 rounded transition-colors block"
                            >
                                • {problem.message}
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
