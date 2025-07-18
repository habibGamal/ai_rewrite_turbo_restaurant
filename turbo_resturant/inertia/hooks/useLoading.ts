import { useState } from "react";

const useLoading = () => {
    const [loading, setLoading] = useState(false);

    const stateLoading = {
        onStart: () => setLoading(true),
        onFinish: () => setLoading(false),
    };
    return { loading, setLoading, stateLoading };
};

export default useLoading;
