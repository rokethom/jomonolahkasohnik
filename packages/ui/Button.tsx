import { Button } from "@jojomono/ui";
import api from "@jojonono/api";

export const Button = ({ children }: any) => {
  return (
    <button className="px-4 py-2 bg-blue-500 text-white rounded">
      {children}
    </button>
  );
};