// import { ErrorBag, Errors } from "@inertiajs/inertia";
// import { useState } from "react";

// const useFormError = () => {
//     const [errors, setErrors] = useState<Errors & ErrorBag>();

//     const isError = (property: string) =>
//         errors?.hasOwnProperty(property) ? "error" : "success";
//     const getErrorMessage = (property: string) => errors?.[property];
//     const getError = (property: string):{
//         validateStatus: "error" | "success",
//         help: string
//     } => ({
//         validateStatus: isError(property),
//         help: isError(property) ? getErrorMessage(property)! : "",
//     });
//     return { setErrors, getError ,errors};
// };

// export default useFormError;
