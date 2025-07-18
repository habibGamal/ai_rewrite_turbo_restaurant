import React from "react";
import { Button } from "antd";
import { DeleteOutlined } from "@ant-design/icons";

export default function DeleteButton({ onClick }: { onClick?: () => void }) {
    return (
        <Button
            danger
            type="primary"
            onClick={onClick}
            icon={<DeleteOutlined />}
        />
    );
}
