import { Application } from "@hotwired/stimulus";
import AuthController from "./auth_controller.js";
import PasswordToggleController  from "./credention/password-toggle_controller.js";
import PasswordStrengthController   from "./credention/password-strength_controller.js";
import LayoutController   from "./components/layout_controller.js";
import GeneratorController from './generator/generator_controller.js';
import SessionManagerController from "./session_manager_controller.js";
import PendingLoginController from "./pending_login_controller.js";
import NavController from "./components/nav_controller.js";
import ThemeController from "./components/theme_controller.js";
import LocaleSwitcherController from "./components/locale_switcher_controller.js";
import ToastsController from "./components/toasts_controller.js";
import HeaderController from "./components/header_controller.js";
import FaqAccordionController from "./components/faq_accordion_controller.js";
import PwaInstallController from "./components/pwa_install_controller.js";
import HelpCenterController from "./pages/help_center_controller.js";
import CredentialIndexController from "./pages/credential_index_controller.js";
import SharedAccessIndexController from "./pages/shared_access_index_controller.js";
import TeamShowController from "./pages/team_show_controller.js";
import HelpArticleController from "./pages/help_article_controller.js";
import AdminEmailPreviewController from "./admin_email_preview_controller.js";




const application = Application.start();
application.register("auth", AuthController);
application.register('password-toggle', PasswordToggleController);
application.register('password-strength', PasswordStrengthController);
application.register("layout", LayoutController);
application.register('generator', GeneratorController)
application.register('session-manager', SessionManagerController)
application.register('pending-login', PendingLoginController)
application.register("nav", NavController);
application.register("theme", ThemeController);
application.register("locale-switcher", LocaleSwitcherController);
application.register("toasts", ToastsController);
application.register("header", HeaderController);
application.register("faq-accordion", FaqAccordionController);
application.register("pwa-install", PwaInstallController);
application.register("help-center", HelpCenterController);
application.register("credential-index", CredentialIndexController);
application.register("shared-access-index", SharedAccessIndexController);
application.register("team-show", TeamShowController);
application.register("help-article", HelpArticleController);
application.register("admin-email-preview", AdminEmailPreviewController);
