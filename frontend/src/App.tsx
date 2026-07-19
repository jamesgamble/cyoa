import { Routes, Route } from "react-router-dom";
import { PublicLayout } from "./layouts/PublicLayout";
import { AccountLayout } from "./layouts/AccountLayout";
import { ManageLayout } from "./layouts/ManageLayout";
import { MasterLayout } from "./layouts/MasterLayout";
import { NotFoundState } from "./states";
import { Home, Discover, Start, Login, Register } from "./pages/public";
import { Help, HelpTopic } from "./pages/help";
import { ChangelogIndex, ChangelogVersion } from "./pages/changelog";
import { Account, Manage, MasterLogin, Master } from "./pages/protected";

export default function App() {
  return (
    <Routes>
      <Route element={<PublicLayout />}>
        <Route path="/" element={<Home />} />
        <Route path="/discover" element={<Discover />} />
        <Route path="/start" element={<Start />} />
        <Route path="/help" element={<Help />} />
        <Route path="/help/:topic" element={<HelpTopic />} />
        <Route path="/changelog" element={<ChangelogIndex />} />
        <Route path="/changelog/:version" element={<ChangelogVersion />} />
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        <Route path="*" element={<NotFoundState />} />
      </Route>
      <Route element={<AccountLayout />}>
        <Route path="/account" element={<Account />} />
      </Route>
      <Route element={<ManageLayout />}>
        <Route path="/manage/:slug" element={<Manage />} />
      </Route>
      <Route element={<MasterLayout />}>
        <Route path="/master/login" element={<MasterLogin />} />
        <Route path="/master" element={<Master />} />
      </Route>
    </Routes>
  );
}
