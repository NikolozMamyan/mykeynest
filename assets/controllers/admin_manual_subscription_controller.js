import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['plan', 'teamSetting'];

  connect() {
    this.refresh();
  }

  refresh() {
    const isTeam = this.planTarget.value === 'team';

    this.teamSettingTargets.forEach((setting) => {
      setting.hidden = !isTeam;
      setting.querySelectorAll('input').forEach((input) => {
        input.disabled = !isTeam;
      });
    });
  }
}
