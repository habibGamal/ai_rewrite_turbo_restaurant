import { useState } from "react";

const useTableSearch = (defaultValueForSelect: string) => {
    const [search, setSearch] = useState<string>("");
    const [attribute, setAttribute] = useState<string>(defaultValueForSelect);
    const [searchMode, setSearchMode] = useState<boolean>(false);
    const exitSearchMode = () => setSearchMode(false);
    const enterSearchMode = () => setSearchMode(true);
    return {
        search,
        setSearch,
        attribute,
        setAttribute,
        exitSearchMode,
        enterSearchMode,
        searchMode,
    };
};

export default useTableSearch;
