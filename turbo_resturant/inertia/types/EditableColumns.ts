import ColumnTypes from "./ColumnTypes.js";
import InvoiceHandler from "./InvoiceHandler.js";

type EditableColumns = (ColumnTypes[number] & {
    editable?: boolean;
    dataIndex: string;
    renderWithHandler?: (
        handler: InvoiceHandler<any>
    ) => (_: any, record: any) => JSX.Element;
})[]

export default EditableColumns;
