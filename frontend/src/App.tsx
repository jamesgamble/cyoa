import { Routes, Route } from "react-router-dom";
import { HelpProvider } from "./components/GlobalHelp";
import { PublicLayout } from "./layouts/PublicLayout";
import { AccountLayout } from "./layouts/AccountLayout";
import { ManageLayout } from "./layouts/ManageLayout";
import { MasterLayout } from "./layouts/MasterLayout";
import { NotFoundState } from "./states";
import { Home, Discover, Start, Login, Register } from "./pages/public";
import { Adventure } from "./pages/Adventure";
import { Reader } from "./pages/Reader";
import { Help, HelpTopic } from "./pages/help";
import { ChangelogIndex, ChangelogVersion } from "./pages/changelog";
import { Account, Manage, MasterLogin, Master } from "./pages/protected";
import { DesignSystem } from "./pages/DesignSystem";

export default function App() {
  return (
    <HelpProvider>
    <Routes>
      <Route element={<PublicLayout />}>
        <Route path="/" element={<Home />} />
        <Route path="/discover" element={<Discover />} />
        <Route path="/adventure/:slug" element={<Adventure />} />
        <Route path="/adventure/:slug/read" element={<Reader />} />
        <Route path="/adventure/:slug/read/:sceneId" element={<Reader />} />
        <Route path="/start" element={<Start />} />
        <Route path="/help" element={<Help />} />
        <Route path="/help/:topic" element={<HelpTopic />} />
        <Route path="/changelog" element={<ChangelogIndex />} />
        <Route path="/changelog/:version" element={<ChangelogVersion />} />
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        <Route path="/design-system" element={<DesignSystem />} />
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
    </HelpProvider>
  );
}
