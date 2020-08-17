<?php
/*
 * Copyright 2014 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not
 * use this file except in compliance with the License. You may obtain a copy of
 * the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations under
 * the License.
 */

class Google_Service_Dialogflow_GoogleCloudDialogflowV2SuggestArticlesResponse extends Google_Collection
{
  protected $collection_key = 'articleAnswers';
  protected $articleAnswersType = 'Google_Service_Dialogflow_GoogleCloudDialogflowV2ArticleAnswer';
  protected $articleAnswersDataType = 'array';
  public $contextSize;
  public $latestMessage;

  /**
   * @param Google_Service_Dialogflow_GoogleCloudDialogflowV2ArticleAnswer
   */
  public function setArticleAnswers($articleAnswers)
  {
    $this->articleAnswers = $articleAnswers;
  }
  /**
   * @return Google_Service_Dialogflow_GoogleCloudDialogflowV2ArticleAnswer
   */
  public function getArticleAnswers()
  {
    return $this->articleAnswers;
  }
  public function setContextSize($contextSize)
  {
    $this->contextSize = $contextSize;
  }
  public function getContextSize()
  {
    return $this->contextSize;
  }
  public function setLatestMessage($latestMessage)
  {
    $this->latestMessage = $latestMessage;
  }
  public function getLatestMessage()
  {
    return $this->latestMessage;
  }
}
