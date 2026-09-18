import { Routes, Route } from "react-router-dom";
import { HelpProvider } from "./components/GlobalHelp";
import { PublicLayout } from "./layouts/PublicLayout";
import { AccountLayout } from "./layouts/AccountLayout";
import { ManageLayout } from "./layouts/ManageLayout";
import { MasterLayout } from "./layouts/MasterLayout";
import { NotFoundState } from "./states";
import {
  Home, Discover, Login, Register,
  ForgotPassword, ResetPassword, VerifyEmail, ChangePassword,
} from "./pages/public";
import { Adventure } from "./pages/Adventure";
import { CreateAdventure } from "./pages/CreateAdventure";
import { SubmitBranch } from "./pages/SubmitBranch";
import { Reader } from "./pages/Reader";
import { StoryMap } from "./pages/StoryMap";
import { Preview } from "./pages/Preview";
import { ManageAdventure } from "./pages/ManageAdventure";
import { Help, HelpTopic } from "./pages/help";
import { ChangelogIndex, ChangelogVersion } from "./pages/changelog";
import { MasterLogin, Master, MasterEmailSettings, MasterEmailQueue } from "./pages/protected";
import {
  AccountOverview, AccountProfilePage, AccountSecurityPage,
  AccountNotificationsPage, AccountAdventuresPage,
  AccountContributionsPage, AccountBookmarksPage,
} from "./pages/AccountPages";
import { DesignSystem } from "./pages/DesignSystem";
import { Invitation } from "./pages/Invitation";
import { AccountInboxPage } from "./pages/Inbox";

export default function App() {
  return (
    <HelpProvider>
    <Routes>
      <Route element={<PublicLayout />}>
        <Route path="/" element={<Home />} />
        <Route path="/discover" element={<Discover />} />
        <Route path="/adventure/:slug" element={<Adventure />} />
        <Route path="/adventure/:slug/map" element={<StoryMap />} />
        <Route path="/adventure/:slug/preview" element={<Preview />} />
        <Route path="/adventure/:slug/branch" element={<SubmitBranch />} />
        <Route path="/adventure/:slug/read" element={<Reader />} />
        <Route path="/adventure/:slug/read/:sceneId" element={<Reader />} />
        <Route path="/start" element={<CreateAdventure />} />
        <Route path="/help" element={<Help />} />
        <Route path="/help/:topic" element={<HelpTopic />} />
        <Route path="/changelog" element={<ChangelogIndex />} />
        <Route path="/changelog/:version" element={<ChangelogVersion />} />
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        <Route path="/forgot-password" element={<ForgotPassword />} />
        <Route path="/reset-password" element={<ResetPassword />} />
        <Route path="/verify" element={<VerifyEmail />} />
        <Route path="/change-password" element={<ChangePassword />} />
        <Route path="/invitations/:token" element={<Invitation />} />
        <Route path="/design-system" element={<DesignSystem />} />
        <Route path="*" element={<NotFoundState />} />
      </Route>
      <Route element={<AccountLayout />}>
        <Route path="/account" element={<AccountOverview />} />
        <Route path="/account/inbox" element={<AccountInboxPage />} />
        <Route path="/account/profile" element={<AccountProfilePage />} />
        <Route path="/account/security" element={<AccountSecurityPage />} />
        <Route path="/account/notifications" element={<AccountNotificationsPage />} />
        <Route path="/account/adventures" element={<AccountAdventuresPage />} />
        <Route path="/account/contributions" element={<AccountContributionsPage />} />
        <Route path="/account/bookmarks" element={<AccountBookmarksPage />} />
      </Route>
      <Route element={<ManageLayout />}>
        <Route path="/manage/:slug" element={<ManageAdventure />} />
      </Route>
      <Route element={<MasterLayout />}>
        <Route path="/master/login" element={<MasterLogin />} />
        <Route path="/master" element={<Master />} />
        <Route path="/master/settings/email" element={<MasterEmailSettings />} />
        <Route path="/master/email-queue" element={<MasterEmailQueue />} />
      </Route>

    </Routes>
    </HelpProvider>
  );
}
