import { useState } from "react";
interface DataType {
  key: React.Key;
}
const useEditableTable = <T extends DataType>() => {
  const [dataSource, setDataSource] = useState<T[]>([]);

  const [count, setCount] = useState(2);

  const handleDelete = (key: React.Key) => {
    const newData = dataSource.filter((item) => item.key !== key);
    setDataSource(newData);
  };

  const handleAdd = (data:T) => {
    setDataSource([...dataSource, { ...data, key: count }]);
    setCount(count + 1);
  };

  const handleSave = (row: T) => {
    const newData = [...dataSource];
    const index = newData.findIndex((item) => row.key === item.key);
    const item = newData[index];
    newData.splice(index, 1, {
      ...item,
      ...row,
    });
    setDataSource(newData);
  };
  return { dataSource, handleDelete, handleAdd, handleSave };
};

export default useEditableTable;
