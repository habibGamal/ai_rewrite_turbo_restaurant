import React from "react";
import { Form, FormInstance, Table } from "antd";
import { createContext } from "react";


interface EditableRowProps {
    index: number;
}

export const EditableContext = createContext<FormInstance<any> | null>(null);

const EditableRow: React.FC<EditableRowProps> = ({ index, ...props }) => {
    const [form] = Form.useForm();
    return (
        <Form form={form} component={false}>
            <EditableContext.Provider value={form}>
                <tr {...props} />
            </EditableContext.Provider>
        </Form>
    );
};

export default EditableRow;
