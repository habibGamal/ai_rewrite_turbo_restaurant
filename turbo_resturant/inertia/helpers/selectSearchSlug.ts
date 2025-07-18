import axios from "axios";
import { SetOptions } from "../components/SelectSearch.js";

export default function selectSearchSlug(slug: string) {
    return async (value: string, setOptions: SetOptions) => {
        const response = await axios.post<{
            options: { id: number; name: string }[];
        }>(`/select-search/${slug}`, {
            value,
        });

        response.status === 200 &&
            setOptions(
                response.data.options.map((option) => ({
                    value: option.id.toString(),
                    label: option.name,
                }))
            );
    };
}
