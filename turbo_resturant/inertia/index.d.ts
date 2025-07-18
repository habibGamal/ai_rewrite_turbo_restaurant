import type CategoriesController from '#controllers/categories_controller'
type JsonPrimitive = string | number | boolean | string | number | boolean | null
type NonJsonPrimitive = undefined | symbol
type IsAny<T> = 0 extends 1 & T ? true : false
type FilterKeys<TObj extends object, TFilter> = {
  [TKey in keyof TObj]: TObj[TKey] extends TFilter ? TKey : never
}[keyof TObj]
/**
 * Convert a type to a JSON-serialized version of itself
 *
 * This is useful when sending data from client to server, as it ensure the
 * resulting type will match what the client will receive after JSON serialization.
 */
type SerializeTuple<T extends [unknown, ...unknown[]]> = {
  [k in keyof T]: T[k] extends NonJsonPrimitive ? null : Serialize<T[k]>
}
/** JSON serialize objects (not including arrays) and classes */
type SerializeObject<T extends object> = {
  [k in keyof Omit<T, FilterKeys<T, NonJsonPrimitive>>]: Serialize<T[k]>;
}
type Serialize<T> =
  IsAny<T> extends true
    ? any
    : T extends JsonPrimitive
      ? T
      : T extends Map<any, any> | Set<any>
        ? Record<string, never>
        : T extends (...args: any[]) => any
          ? ReturnType<T>
          : T extends NonJsonPrimitive
            ? never
            : T extends {
                  toJSON(): infer U
                }
              ? U
              : T extends []
                ? []
                : T extends [unknown, ...unknown[]]
                  ? SerializeTuple<T>
                  : T extends ReadonlyArray<infer U>
                    ? (U extends NonJsonPrimitive ? null : Serialize<U>)[]
                    : T extends object
                      ? SerializeObject<T>
                      : never
export type InferPageProps<Controller, Method extends keyof Controller> = Controller[Method] extends (
  ...args: any[]
) => any
  ? Serialize<Exclude<Awaited<ReturnType<Controller[Method]>>, string>['props']>
  : never

