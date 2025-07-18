import React from "react";
import { Button } from "antd";
import { EditOutlined } from "@ant-design/icons";

export default function EditButton({ onClick }: { onClick?: () => void }) {
    return (
        <Button
            onClick={onClick}
            icon={<EditOutlined />}
        />
    );
}
