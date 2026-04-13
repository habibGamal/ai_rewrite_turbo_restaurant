import React, { useState, useEffect } from "react";
import { ClockCircleOutlined } from "@ant-design/icons";

interface OrderTimerProps {
    createdAt: string;
}

const formatDuration = (seconds: number): string => {
    const hrs = Math.floor(seconds / 3600);
    const mins = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;

    const pad = (n: number) => n.toString().padStart(2, "0");

    if (hrs > 0) {
        return `${pad(hrs)}:${pad(mins)}:${pad(secs)}`;
    }
    return `${pad(mins)}:${pad(secs)}`;
};

export const OrderTimer: React.FC<OrderTimerProps> = ({ createdAt }) => {
    const [elapsed, setElapsed] = useState(() => {
        const diff = Date.now() - new Date(createdAt).getTime();
        return Math.max(0, Math.floor(diff / 1000));
    });

    useEffect(() => {
        const interval = setInterval(() => {
            setElapsed((prev) => prev + 1);
        }, 1000);

        return () => clearInterval(interval);
    }, []);

    return (
        <span className="flex items-center gap-1 text-sm tabular-nums">
            <ClockCircleOutlined />
            {formatDuration(elapsed)}
        </span>
    );
};
