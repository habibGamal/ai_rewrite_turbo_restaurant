import { SortOrder, SorterResult } from "antd/es/table/interface";
import { useState } from "react";

const useSortTable = (defaultColumnKey?: string) => {
    const [sortInfo, setSortedInfo] = useState<SorterResult<any>>({
        order: null,
        columnKey: defaultColumnKey,
    });
    const updateSortState = (columnKey: string) => {
        // null -> descend -> ascend -> null
        if (sortInfo.order === "descend") {
            setSortedInfo({ order: "ascend", columnKey });
            return;
        }
        if (sortInfo.order === "ascend") {
            setSortedInfo({ order: null, columnKey });
            return;
        }
        if (sortInfo.order === null)
            setSortedInfo({ order: "descend", columnKey });
    };
    const resetSortState = () => {
        setSortedInfo({ order: null, columnKey: undefined });
    };
    const getSortProps = (columnKey: string) => {
        return {
            sorter: true,
            sortOrder: sortInfo.columnKey === columnKey ? sortInfo.order : null,
            onHeaderCell: () => {
                return {
                    onClick: () => {
                        updateSortState(columnKey);
                    },
                };
            },
            sortDirections: ["descend", "ascend"] as SortOrder[],
        };
    };

    return { getSortProps, resetSortState };
};

export default useSortTable;
