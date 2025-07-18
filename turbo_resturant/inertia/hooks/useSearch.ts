import { useState } from "react";

const useSearch = (defaultAttribute: string = "barcode") => {
    const [search, setSearch] = useState({
        attribute: defaultAttribute,
        value: "",
    });

    const changeSearchAttribute = (value: string) =>
        setSearch({ ...search, attribute: value });

    const changeSearchValue = (value: string) =>
        setSearch({ ...search, value });

    return {
        data: search,
        changeSearchAttribute,
        changeSearchValue,
    };
};

export default useSearch;
