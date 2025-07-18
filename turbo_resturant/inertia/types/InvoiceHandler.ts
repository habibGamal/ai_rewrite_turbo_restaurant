export default interface InvoiceHandler<InvoiceItem> {
    onSearch: () => void;
    cancelOperation: () => void;
    onAddInvoiceItem: (model: any) => void;
    onEditInvoiceItem: (model: any) => void;
    onDeleteInvoiceItem: (model: any) => void;
    onSubmit: () => void;
    getInvoiceItems: () => InvoiceItem[];
}
