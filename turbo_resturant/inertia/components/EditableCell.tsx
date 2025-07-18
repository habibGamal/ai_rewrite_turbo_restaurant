import React, { useContext, useEffect, useRef, useState } from "react";
import { Form, Input, InputRef } from "antd";
import { EditableContext } from "./EditableRow.js";

interface EditableCellProps<T> {
    title: React.ReactNode;
    editable: boolean;
    children: React.ReactNode;
    dataIndex: keyof T;
    record: T;
    handleSave: (record: T) => void;
}

function EditableCell<T>({
    title,
    editable,
    children,
    dataIndex,
    record,
    handleSave,
    ...restProps
}: EditableCellProps<T>) {
    const [editing, setEditing] = useState(false);
    const inputRef = useRef<InputRef>(null);
    const form = useContext(EditableContext)!;

    useEffect(() => {
        if (editing) {
            inputRef.current!.focus();
            inputRef.current!.select();
        }
    }, [editing]);

    const toggleEdit = () => {
        setEditing(!editing);
        form.setFieldsValue({ [dataIndex]: record[dataIndex] });
    };

    const save = async () => {
        try {
            const values = await form.validateFields();

            toggleEdit();
            handleSave({ ...record, ...values });
        } catch (errInfo) {

        }
    };

    let childNode = children;

    if (editable) {
        childNode = editing ? (
            <Form.Item
                style={{ margin: 0 }}
                name={dataIndex as any}
                className="w-24"
                rules={[
                    {
                        required: true,
                        message: `مطلوب ${title}`,
                    },
                ]}
            >
                <Input ref={inputRef} onPressEnter={save} onBlur={save} />
            </Form.Item>
        ) : (
            <div
                data-barcode={`${(record as any).barcode}-${dataIndex as any}`}
                data-key={`${(record as any).key}-${dataIndex as any}`}
                className={`editable-cell-value-wrap editable-${
                    dataIndex as any
                }`}
                style={{ paddingRight: 24 }}
                onClick={toggleEdit}
            >
                {children}
            </div>
        );
    }

    return <td {...restProps}>{childNode}</td>;
}

export default EditableCell;
