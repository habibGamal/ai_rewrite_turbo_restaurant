import EditableColumns from "../types/EditableColumns.js";

export default function mapEditableColumns<T>(
    columns: EditableColumns,
    onSave: (record: T) => void,
) {
    return columns.map((col) => {
        if (!col.editable) {
            return col;
        }
        return {
            ...col,
            onCell: (record: T) => ({
                record,
                editable: col.editable,
                dataIndex: col.dataIndex,
                title: col.title,
                handleSave: onSave,
            }),
        };
    });
}
